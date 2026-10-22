<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cliente de los servicios en línea del SII: autenticación (semilla → token firmado), envío de DTE y de boletas, y consulta del estado de los envíos y
 * de los documentos.
 *
 * Los servicios SOAP del SII (RPC/encoded, de otra época) no funcionan con ext-soap en un PHP moderno, así que el sobre SOAP se arma a mano sobre HTTP.
 * Las boletas usan, en cambio, la API REST propia del SII. Los puntos de conexión, los parámetros y el formato de las respuestas están tomados de los
 * WSDL y de los manuales publicados por el SII; el comportamiento real (por ejemplo, qué estados devuelve en cada caso) lo define el SII y puede cambiar:
 * esta librería no puede garantizar la aceptación de un documento, solo entregar el XML conforme al esquema y firmado según el formato publicado.
 *
 * Para pruebas sin red se puede entregar un transporte propio (tercer parámetro): una función que recibe la solicitud y devuelve ['status' => int, 'body' => string].
 */
final class SiiClient
{
    public const CERTIFICACION = 'certificacion';
    public const PRODUCCION = 'produccion';

    private const HOSTS = [
        self::CERTIFICACION => ['dte' => 'https://maullin.sii.cl', 'api' => 'https://apicert.sii.cl', 'boleta' => 'https://pangal.sii.cl'],
        self::PRODUCCION => ['dte' => 'https://palena.sii.cl', 'api' => 'https://api.sii.cl', 'boleta' => 'https://rahue.sii.cl'],
    ];

    /** Códigos de STATUS que devuelve DTEUpload — manual OI2003_UPDTE_MDE, punto 2.1. */
    private const UPLOAD_STATUS_LABELS = [
        '0' => 'Upload OK.',
        '1' => 'El remitente no tiene permiso para enviar.',
        '2' => 'Error en el tamaño del archivo (muy grande o muy chico).',
        '3' => 'Archivo cortado (tamaño distinto al informado).',
        '5' => 'No está autenticado (token inválido o vencido).',
        '6' => 'La empresa no está autorizada a enviar archivos.',
        '7' => 'Esquema XML inválido.',
        '8' => 'Firma del documento inválida.',
        '9' => 'Sistema bloqueado.',
    ];

    /** Estados que informa el SII al consultar un envío (QueryEstUp). El texto que viaja en la respuesta del SII (GLOSA) manda sobre este. */
    public const ESTADOS_ENVIO = [
        'EPR' => 'Envío procesado.',
        'RSC' => 'Rechazado por error en el esquema.',
        'RFR' => 'Rechazado por error en la firma.',
        'RCT' => 'Rechazado por error en la carátula.',
        'RCH' => 'Rechazado.',
        'REC' => 'Recibido.',
        'DOK' => 'Documento recibido por el SII, datos coinciden.',
    ];

    /** Estados que informa el SII al consultar un documento (QueryEstDte). */
    public const ESTADOS_DTE = [
        'DOK' => 'Documento recibido por el SII. Datos coinciden con los registrados.',
        'DNK' => 'Documento recibido por el SII pero los datos no coinciden con los registrados.',
        'FAU' => 'Documento no recibido por el SII.',
        'FNA' => 'Documento no autorizado.',
        'FAN' => 'Documento anulado.',
        'EMP' => 'Empresa no autorizada a enviar documentos.',
        'TMD' => 'Existe una nota de débito que modifica el texto del documento.',
        'TMC' => 'Existe una nota de crédito que modifica el texto del documento.',
        'MMD' => 'Existe una nota de débito que modifica los montos del documento.',
        'MMC' => 'Existe una nota de crédito que modifica los montos del documento.',
        'AND' => 'Existe una nota de débito que anula el documento.',
        'ANC' => 'Existe una nota de crédito que anula el documento.',
    ];

    private readonly TokenRequestSigner $tokenSigner;
    /** @var callable(array<string, mixed>): array{status: int, body: string} */
    private $transport;

    /** @param (callable(array<string, mixed>): array{status: int, body: string})|null $transport */
    public function __construct(
        private readonly string $environment = self::CERTIFICACION,
        ?TokenRequestSigner $tokenSigner = null,
        ?callable $transport = null,
    ) {
        if (!isset(self::HOSTS[$this->environment])) {
            throw new RuntimeException("Ambiente SII desconocido: \"{$this->environment}\" (use SiiClient::CERTIFICACION o SiiClient::PRODUCCION).");
        }
        $this->tokenSigner = $tokenSigner ?? new TokenRequestSigner();
        $this->transport = $transport ?? [$this, 'curl'];
    }

    // ------------------------------------------------------------------ autenticación (servicios SOAP)

    /** Semilla (número de una sola vez) que hay que firmar dentro de los próximos ~2 minutos para canjearla por un token. */
    public function getSeed(): string
    {
        $response = $this->soap($this->dte('/DTEWS/CrSeed.jws'), 'getSeed', []);

        return $this->readSiiValue($this->extractSoapReturn($response, 'getSeedReturn'), 'SEMILLA');
    }

    /** Canjea una semilla ya firmada por el token de sesión que autentica el resto de las llamadas al SII. */
    public function getToken(string $semilla, Certificate $certificate): string
    {
        $response = $this->soap($this->dte('/DTEWS/GetTokenFromSeed.jws'), 'getToken', ['pszXml' => $this->signedTokenRequest($semilla, $certificate)]);

        return $this->readSiiValue($this->extractSoapReturn($response, 'getTokenReturn'), 'TOKEN');
    }

    /** Atajo: obtiene la semilla y la canjea por token en un solo paso. */
    public function authenticate(Certificate $certificate): string
    {
        return $this->getToken($this->getSeed(), $certificate);
    }

    // ------------------------------------------------------------------ autenticación (API REST de boletas)

    /** Token para la API de boletas: es otro servicio del SII y su token no sirve para los servicios de DTE (ni al revés). */
    public function authenticateBoleta(Certificate $certificate): string
    {
        $semilla = $this->http('GET', $this->api('/recursos/v1/boleta.electronica.semilla'), ['Accept: application/xml']);
        $seed = $this->readSiiValue($semilla, 'SEMILLA');
        $respuesta = $this->http('POST', $this->api('/recursos/v1/boleta.electronica.token'), ['Content-Type: application/xml', 'Accept: application/xml'], $this->signedTokenRequest($seed, $certificate));

        return $this->readSiiValue($respuesta, 'TOKEN');
    }

    // ------------------------------------------------------------------ envío

    /**
     * Sube un <EnvioDTE> ya armado y firmado (ver EnvioDteBuilder::build()). No es un servicio SOAP sino un POST HTTP multipart/form-data, autenticado con
     * el token vía cookie (manual OI2003_UPDTE_MDE y ejemplo oficial del SII).
     *
     * @return array{trackId: string, status: string} El trackId sirve para consultar el estado con {@see getUploadStatus()}.
     * @throws RuntimeException Si el SII rechaza el envío (status distinto de "0") o la respuesta no se puede interpretar.
     */
    public function uploadEnvioDte(DOMDocument $signedEnvioDte, string $token, string $rutSender, string $rutCompany): array
    {
        $body = $this->upload($this->dte('/cgi_dte/UPL/DTEUpload'), $signedEnvioDte, $token, $rutSender, $rutCompany, 'EnvioDTE.xml', true);

        return $this->parseUploadResponse($body);
    }

    /**
     * Sube un <EnvioBOLETA> (ver EnvioDteBuilder::buildBoleta()) a la API de boletas. Requiere el token de {@see authenticateBoleta()}.
     *
     * @return array<string, mixed> Respuesta del SII (JSON): `trackid`, `rut_emisor`, `rut_envia`, `fecha_recepcion`, `estado`, `file`.
     */
    public function uploadBoleta(DOMDocument $signedEnvioBoleta, string $token, string $rutSender, string $rutCompany): array
    {
        $body = $this->upload($this->boleta('/recursos/v1/boleta.electronica.envio'), $signedEnvioBoleta, $token, $rutSender, $rutCompany, 'EnvioBOLETA.xml', false);
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['trackid'])) {
            throw new RuntimeException('El SII no aceptó el envío de boletas o respondió algo inesperado: ' . mb_substr($body, 0, 300));
        }

        return $json;
    }

    // ------------------------------------------------------------------ consultas de estado

    /**
     * Estado de un envío de DTE (servicio QueryEstUp).
     *
     * @return array{estado: string, descripcion: string, glosa: string, trackId: string, aceptados: ?int, rechazados: ?int, reparos: ?int, respuesta: string}
     */
    public function getUploadStatus(string $rutCompany, string $trackId, string $token): array
    {
        [$numero, $dv] = $this->splitRut($rutCompany);
        $inner = $this->extractSoapReturn(
            $this->soap($this->dte('/DTEWS/QueryEstUp.jws'), 'getEstUp', ['RutCompania' => $numero, 'DvCompania' => $dv, 'TrackId' => $trackId, 'Token' => $token]),
            'getEstUpReturn'
        );

        return $this->parseEstado($inner, self::ESTADOS_ENVIO, $trackId);
    }

    /**
     * Estado de un documento ya enviado (servicio QueryEstDte): si el SII lo recibió y si los datos coinciden.
     *
     * @param string $fechaEmision AAAA-MM-DD (se envía al SII como DDMMAAAA).
     * @return array{estado: string, descripcion: string, glosa: string, trackId: string, aceptados: ?int, rechazados: ?int, reparos: ?int, respuesta: string}
     */
    public function getDteStatus(string $rutConsultante, string $rutCompany, string $rutReceptor, int $tipoDte, int $folio, string $fechaEmision, int $monto, string $token): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaEmision, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('La fecha de emisión debe tener el formato AAAA-MM-DD.');
        }
        [$rc, $dvc] = $this->splitRut($rutConsultante);
        [$re, $dve] = $this->splitRut($rutCompany);
        [$rr, $dvr] = $this->splitRut($rutReceptor);
        $inner = $this->extractSoapReturn(
            $this->soap($this->dte('/DTEWS/QueryEstDte.jws'), 'getEstDte', [
                'RutConsultante' => $rc, 'DvConsultante' => $dvc, 'RutCompania' => $re, 'DvCompania' => $dve, 'RutReceptor' => $rr, 'DvReceptor' => $dvr,
                'TipoDte' => (string) $tipoDte, 'FolioDte' => (string) $folio, 'FechaEmisionDte' => $m[3] . $m[2] . $m[1], 'MontoDte' => (string) $monto, 'Token' => $token,
            ]),
            'getEstDteReturn'
        );

        return $this->parseEstado($inner, self::ESTADOS_DTE, '');
    }

    /**
     * Estado de un envío de boletas (API REST). Requiere el token de {@see authenticateBoleta()}.
     *
     * @return array<string, mixed> Respuesta del SII (JSON): `estado`, `estadistica`, `detalle_rep_rech`...
     */
    public function getBoletaUploadStatus(string $rutEmisor, string $trackId, string $token): array
    {
        [$numero, $dv] = $this->splitRut($rutEmisor);
        $body = $this->http('GET', $this->api("/recursos/v1/boleta.electronica.envio/{$numero}-{$dv}-" . rawurlencode($trackId)), ['Accept: application/json', 'Cookie: TOKEN=' . $token]);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('El SII respondió algo inesperado al consultar el envío de boletas: ' . mb_substr($body, 0, 300));
        }

        return $json;
    }

    // ------------------------------------------------------------------ detalle interno

    private function dte(string $path): string
    {
        return self::HOSTS[$this->environment]['dte'] . $path;
    }

    private function api(string $path): string
    {
        return self::HOSTS[$this->environment]['api'] . $path;
    }

    private function boleta(string $path): string
    {
        return self::HOSTS[$this->environment]['boleta'] . $path;
    }

    private function signedTokenRequest(string $semilla, Certificate $certificate): string
    {
        $xml = $this->tokenSigner->sign($semilla, $certificate)->saveXML();
        if ($xml === false) {
            throw new RuntimeException('No fue posible serializar la solicitud de token firmada.');
        }

        return $xml;
    }

    private function upload(string $url, DOMDocument $envio, string $token, string $rutSender, string $rutCompany, string $nombre, bool $comoNavegadorAntiguo): string
    {
        $xml = $envio->saveXML();
        if ($xml === false) {
            throw new RuntimeException('No fue posible serializar el envío antes de subirlo.');
        }
        [$senderNumber, $senderDv] = $this->splitRut($rutSender);
        [$companyNumber, $companyDv] = $this->splitRut($rutCompany);

        // Sin este User-Agent, DTEUpload responde una página HTML de error genérica en vez del XML <RECEPCIONDTE>: es un mecanismo antiguo documentado en el
        // propio manual del SII (OI2003_UPDTE_MDE, figura 2.3: el parámetro «PROG 1.0» pide la salida en XML).
        $headers = ['Cookie: TOKEN=' . $token] + ($comoNavegadorAntiguo ? [1 => 'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)'] : [1 => 'Accept: application/json']);

        return $this->http('POST', $url, $headers, [
            'rutSender' => $senderNumber, 'dvSender' => $senderDv, 'rutCompany' => $companyNumber, 'dvCompany' => $companyDv,
            'archivo' => ['filename' => $nombre, 'type' => 'text/xml', 'content' => $xml],
        ]);
    }

    /** @return array{trackId: string, status: string} */
    private function parseUploadResponse(string $responseXml): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($responseXml)) {
            throw new RuntimeException('La respuesta del SII al subir el EnvioDTE no es un XML válido: ' . mb_substr($responseXml, 0, 300));
        }

        $statusNodes = $doc->getElementsByTagName('STATUS');
        $status = $statusNodes->length > 0 ? $statusNodes->item(0)->textContent : null;

        if ($status !== '0') {
            $label = self::UPLOAD_STATUS_LABELS[$status] ?? 'Error desconocido.';
            throw new RuntimeException("El SII rechazó el envío del DTE (STATUS={$status}): {$label}");
        }

        $trackIdNodes = $doc->getElementsByTagName('TRACKID');
        if ($trackIdNodes->length === 0) {
            throw new RuntimeException('El SII aceptó el envío pero no devolvió un TRACKID.');
        }

        return ['trackId' => $trackIdNodes->item(0)->textContent, 'status' => $status];
    }

    /**
     * @param array<string, string> $descripciones
     * @return array{estado: string, descripcion: string, glosa: string, trackId: string, aceptados: ?int, rechazados: ?int, reparos: ?int, respuesta: string}
     */
    private function parseEstado(string $innerXml, array $descripciones, string $trackId): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($innerXml)) {
            throw new RuntimeException('El SII devolvió una respuesta que no se pudo interpretar: ' . mb_substr($innerXml, 0, 300));
        }
        $texto = static function (string $tag) use ($doc): ?string {
            $n = $doc->getElementsByTagName($tag);

            return $n->length > 0 ? trim($n->item(0)->textContent) : null;
        };
        $estado = $texto('ESTADO') ?? '';
        if ($estado === '') {
            throw new RuntimeException('La respuesta del SII no informa un ESTADO: ' . mb_substr($innerXml, 0, 300));
        }
        // ESTADO numérico = error del servicio (token vencido, sin permiso...); un código de letras es el estado del envío o del documento.
        $glosa = $texto('GLOSA') ?? '';
        $entero = static fn (?string $v): ?int => $v === null || $v === '' ? null : (int) $v;
        $aceptados = $entero($texto('ACEPTADOS'));
        $rechazados = $entero($texto('RECHAZADOS'));
        $reparos = $entero($texto('REPAROS'));

        return [
            'estado' => $estado,
            'descripcion' => $descripciones[$estado] ?? ($glosa !== '' ? $glosa : 'Estado desconocido.'),
            'glosa' => $glosa,
            'trackId' => $texto('TRACKID') ?? $trackId,
            'aceptados' => $aceptados,
            'rechazados' => $rechazados,
            'reparos' => $reparos,
            'respuesta' => $innerXml,
        ];
    }

    /** @return array{0: string, 1: string} [RUT sin dígito verificador, dígito verificador] */
    private function splitRut(string $rut): array
    {
        $parts = explode('-', Rut::toDteFormat($rut));

        return [$parts[0], $parts[1]];
    }

    /** @param array<string, string> $params */
    private function soap(string $url, string $operation, array $params): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
        $doc->appendChild($envelope);
        $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Body');
        $envelope->appendChild($body);
        $op = $doc->createElement($operation);
        $op->setAttribute('xmlns', 'http://DefaultNamespace');
        $body->appendChild($op);
        foreach ($params as $nombre => $valor) {
            $param = $doc->createElement($nombre);
            $param->appendChild($doc->createTextNode($valor));
            $op->appendChild($param);
        }

        return $this->http('POST', $url, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'], (string) $doc->saveXML());
    }

    /**
     * @param list<string> $headers
     * @param string|array<string, mixed>|null $body Texto, o campos de un formulario multipart (un campo con `filename`/`content` es un archivo).
     */
    private function http(string $method, string $url, array $headers, string|array|null $body = null): string
    {
        $r = ($this->transport)(['method' => $method, 'url' => $url, 'headers' => array_values($headers), 'body' => $body]);
        if ($r['status'] >= 400) {
            throw new RuntimeException("El SII respondió con un error HTTP {$r['status']} ({$url})" . ($r['body'] !== '' ? ': ' . mb_substr(trim(strip_tags($r['body'])), 0, 200) : '.'));
        }

        return $r['body'];
    }

    /**
     * Transporte por defecto (cURL).
     *
     * @param array<string, mixed> $req
     * @return array{status: int, body: string}
     */
    private function curl(array $req): array
    {
        $ch = curl_init((string) $req['url']);
        $tmp = null;
        $opciones = [
            CURLOPT_CUSTOMREQUEST => $req['method'],
            CURLOPT_HTTPHEADER => array_values(array_filter($req['headers'], static fn (string $h): bool => !str_starts_with($h, 'User-Agent:'))),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ];
        foreach ($req['headers'] as $h) {
            if (str_starts_with($h, 'User-Agent:')) {
                $opciones[CURLOPT_USERAGENT] = trim(substr($h, 11));
            }
        }
        if (is_array($req['body'])) {
            $campos = [];
            foreach ($req['body'] as $k => $v) {
                if (is_array($v)) {
                    $tmp = tempnam(sys_get_temp_dir(), 'sii_');
                    if ($tmp === false || file_put_contents($tmp, $v['content']) === false) {
                        throw new RuntimeException('No fue posible preparar el archivo temporal del envío.');
                    }
                    $campos[$k] = new \CURLFile($tmp, $v['type'], $v['filename']);
                } else {
                    $campos[$k] = $v;
                }
            }
            $opciones[CURLOPT_POST] = true;
            $opciones[CURLOPT_POSTFIELDS] = $campos;
        } elseif ($req['body'] !== null) {
            $opciones[CURLOPT_POSTFIELDS] = $req['body'];
        }
        curl_setopt_array($ch, $opciones);
        try {
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
        if ($body === false) {
            throw new RuntimeException("No fue posible conectar con el SII ({$req['url']}): {$error}");
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /** Extrae el texto (ya des-escapado) del elemento de retorno RPC/encoded dentro del sobre SOAP de respuesta. */
    private function extractSoapReturn(string $soapResponseXml, string $returnElementLocalName): string
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($soapResponseXml)) {
            throw new RuntimeException('La respuesta del SII no es un XML válido: ' . mb_substr($soapResponseXml, 0, 300));
        }

        $nodes = $doc->getElementsByTagName($returnElementLocalName);
        if ($nodes->length === 0) {
            $fault = $doc->getElementsByTagName('faultstring');
            if ($fault->length > 0) {
                throw new RuntimeException("El SII rechazó la solicitud: {$fault->item(0)->textContent}");
            }
            throw new RuntimeException("La respuesta del SII no tiene el elemento esperado <{$returnElementLocalName}>.");
        }

        return $nodes->item(0)->textContent;
    }

    /**
     * Las respuestas del SII (semilla, token) vienen envueltas en <SII:RESPUESTA><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>
     * <SII:RESP_BODY><TAG>valor</TAG></SII:RESP_BODY></SII:RESPUESTA>: ESTADO «00» es éxito y cualquier otro código, un rechazo.
     */
    private function readSiiValue(string $innerXml, string $valueTagName): string
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($innerXml)) {
            throw new RuntimeException('El SII devolvió una respuesta que no se pudo interpretar: ' . mb_substr($innerXml, 0, 300));
        }

        $estadoNodes = $doc->getElementsByTagName('ESTADO');
        $estado = $estadoNodes->length > 0 ? $estadoNodes->item(0)->textContent : null;

        $valueNodes = $doc->getElementsByTagName($valueTagName);
        if ($valueNodes->length > 0 && ($estado === null || $estado === '00')) {
            return $valueNodes->item(0)->textContent;
        }

        $glosaNodes = $doc->getElementsByTagName('GLOSA');
        $glosa = $glosaNodes->length > 0 ? $glosaNodes->item(0)->textContent : 'sin detalle';

        throw new RuntimeException('El SII rechazó la solicitud (ESTADO=' . ($estado ?? 'desconocido') . "): {$glosa}");
    }
}
