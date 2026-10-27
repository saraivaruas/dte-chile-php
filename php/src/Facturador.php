<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fachada de alto nivel: emite y envía documentos tributarios con objetos tipados, en una o dos llamadas.
 *
 *   $f = new Facturador($emisor, $certificado, new ArchivoFolios('folios.json'), SiiClient::CERTIFICACION);
 *   $f->agregarCaf(Caf::fromXml(file_get_contents('CAF33.xml')));
 *   $doc = $f->factura($receptor, [new Linea('Consultoría', 10, 25000)]);
 *   $envio = $f->enviar([$doc]);                  // ResultadoEnvio (trackId)
 *   $estado = $f->estadoEnvio($envio->trackId);
 *
 * Por debajo usa las clases de la capa baja (DteBuilder, TedSigner, DteSigner, EnvioDteBuilder, SiiClient), que siguen disponibles.
 */
final class Facturador
{
    /** @var array<int, Caf> */
    private array $cafs = [];
    private ?string $tokenDte = null;
    private ?string $tokenBoleta = null;
    private readonly SiiClient $sii;

    /**
     * @param array{fecha: string, numero: int} $resolucion Resolución del SII que autoriza al emisor (numero 0 si la autorización fue por internet).
     */
    public function __construct(
        private readonly Emisor $emisor,
        private readonly Certificate $certificado,
        private readonly FolioStore $folios,
        private readonly string $ambiente = SiiClient::CERTIFICACION,
        private readonly array $resolucion = ['fecha' => '2026-01-01', 'numero' => 0],
        ?SiiClient $sii = null,
    ) {
        $this->sii = $sii ?? new SiiClient($ambiente);
    }

    public function agregarCaf(Caf $caf): self
    {
        if (Rut::toDteFormat($caf->issuerRut()) !== Rut::toDteFormat($this->emisor->rut)) {
            throw new InvalidArgumentException("El CAF es de otro contribuyente ({$caf->issuerRut()}), no del emisor {$this->emisor->rut}.");
        }
        $this->cafs[$caf->documentType()] = $caf;

        return $this;
    }

    /**
     * Comprueba la conexión con el SII SIN enviar ningún documento ni necesitar CAF: autentica (semilla firmada → token) en el servicio de facturas
     * y en el de boletas, y dice si el SII aceptó el certificado. Nunca lanza por un fallo del SII: lo informa en el resultado.
     */
    public function probarConexion(): ResultadoConexion
    {
        $errores = [];
        foreach (['facturas' => fn () => $this->tokenDte = $this->sii->authenticate($this->certificado), 'boletas' => fn () => $this->tokenBoleta = $this->sii->authenticateBoleta($this->certificado)] as $servicio => $autenticar) {
            try {
                $autenticar();
            } catch (\Throwable $e) {
                $errores[$servicio] = $e->getMessage();
            }
        }

        return new ResultadoConexion(
            $this->ambiente, !isset($errores['facturas']), !isset($errores['boletas']),
            $this->certificado->subjectRut() ?? '', $this->certificado->notAfter(), $errores,
        );
    }

    /** Tipos de documento para los que hay un CAF cargado. @return int[] */
    public function tiposDisponibles(): array
    {
        return array_keys($this->cafs);
    }

    /** @param Linea[] $lineas */
    public function factura(Receptor $receptor, array $lineas, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::FACTURA_ELECTRONICA, $receptor, $lineas, [], $opciones);
    }

    /** Factura no afecta o exenta (34). @param Linea[] $lineas */
    public function facturaExenta(Receptor $receptor, array $lineas, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::FACTURA_NO_AFECTA_EXENTA, $receptor, $lineas, [], $opciones);
    }

    /**
     * @param Linea[] $lineas
     * @param Referencia[] $referencias Al menos una: el documento que se corrige o anula.
     */
    public function notaCredito(Receptor $receptor, array $lineas, array $referencias, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::NOTA_CREDITO, $receptor, $lineas, $referencias, $opciones);
    }

    /** @param Linea[] $lineas @param Referencia[] $referencias */
    public function notaDebito(Receptor $receptor, array $lineas, array $referencias, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::NOTA_DEBITO, $receptor, $lineas, $referencias, $opciones);
    }

    /**
     * @param Linea[] $lineas
     * @param int $indicadorTraslado 1 operación constituye venta, 2 venta por efectuar, 3 consignación, 4 entrega gratuita, 5 traslado interno, 6 otros traslados no venta, 7 guía de devolución, 8 traslado para exportación, 9 venta para exportación
     */
    public function guiaDespacho(Receptor $receptor, array $lineas, int $indicadorTraslado, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::GUIA_DESPACHO, $receptor, $lineas, [], $opciones + ['indicadorTraslado' => $indicadorTraslado]);
    }

    /** Boleta electrónica (39): precios con IVA incluido; el receptor es opcional. @param Linea[] $lineas */
    public function boleta(array $lineas, ?Receptor $receptor = null, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::BOLETA_ELECTRONICA, $receptor, $lineas, [], $opciones);
    }

    /** Boleta no afecta o exenta (41). @param Linea[] $lineas */
    public function boletaExenta(array $lineas, ?Receptor $receptor = null, array $opciones = []): DocumentoEmitido
    {
        return $this->emitir(DocumentType::BOLETA_NO_AFECTA_EXENTA, $receptor, $lineas, [], $opciones);
    }

    /**
     * Construye, timbra y firma un documento de cualquier tipo.
     *
     * @param Linea[] $lineas
     * @param Referencia[] $referencias
     * @param array<string, mixed> $opciones Campos adicionales de la capa baja (fechaEmision, formaPago, descuentosRecargos, transporte, ...). `fechaEmision` por defecto es hoy en Chile.
     */
    public function emitir(int $tipo, ?Receptor $receptor, array $lineas, array $referencias = [], array $opciones = []): DocumentoEmitido
    {
        if ($lineas === []) {
            throw new InvalidArgumentException('El documento necesita al menos una línea.');
        }
        foreach ($lineas as $l) {
            if (!$l instanceof Linea) {
                throw new InvalidArgumentException('Las líneas deben ser objetos Linea.');
            }
        }
        $caf = $this->cafs[$tipo] ?? throw new RuntimeException('No hay un CAF cargado para ' . DocumentType::label($tipo) . " (tipo {$tipo}). Use agregarCaf().");
        $boleta = DocumentType::isBoleta($tipo);
        if ($receptor === null && !$boleta) {
            throw new InvalidArgumentException('Este tipo de documento exige receptor.');
        }

        $folio = $this->folios->siguiente($tipo, $caf->folioFrom(), $caf->folioTo());
        $datos = [
            'folio' => $folio,
            'fechaEmision' => $opciones['fechaEmision'] ?? (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d'),
            // El esquema de boleta tiene menos campos de emisor (sin actividad económica, correo ni teléfono).
            'emisor' => $boleta ? array_diff_key($this->emisor->toArray(), array_flip(['actividadEconomica', 'correo', 'telefono'])) : $this->emisor->toArray(),
            'items' => array_map(static fn (Linea $l): array => $l->toArray(), array_values($lineas)),
        ] + $opciones;
        if ($receptor !== null) {
            $datos['receptor'] = $receptor->toArray();
        }
        if ($referencias !== []) {
            $datos['referencias'] = array_map(static fn (Referencia $r): array => $r->toArray(), array_values($referencias));
        }

        $builder = new DteBuilder();
        $dte = $boleta ? (new BoletaBuilder())->build($tipo, $datos) : $builder->build($tipo, $datos);
        $monto = $builder->montoTotal($dte);
        $ted = (new TedSigner())->sign($caf, $tipo, $datos + ['montoTotal' => $monto]);
        $builder->attachTed($dte, $ted);
        $firmado = (new DteSigner())->sign($dte, $this->certificado);

        return new DocumentoEmitido(
            $tipo, $folio, $monto, $datos['fechaEmision'], Rut::toDteFormat($this->emisor->rut),
            $receptor !== null ? Rut::toDteFormat($receptor->rut) : BoletaBuilder::RUT_GENERICO,
            $firmado, Pdf417Encoder::tedToBytes($ted),
        );
    }

    /**
     * Arma el sobre firmado y lo sube al SII. Todos los documentos deben ser boletas, o ninguno.
     *
     * @param DocumentoEmitido[] $documentos
     */
    public function enviar(array $documentos): ResultadoEnvio
    {
        $sobre = $this->sobre($documentos);
        $boletas = $documentos[0]->esBoleta();
        $enviaRut = $this->certificado->subjectRut() ?? throw new RuntimeException('El certificado no trae el RUT del titular (serialNumber).');
        $rutEmisor = Rut::toDteFormat($this->emisor->rut);
        $token = $boletas ? ($this->tokenBoleta ??= $this->sii->authenticateBoleta($this->certificado)) : ($this->tokenDte ??= $this->sii->authenticate($this->certificado));
        $r = $boletas ? $this->sii->uploadBoleta($sobre, $token, $enviaRut, $rutEmisor) : $this->sii->uploadEnvioDte($sobre, $token, $enviaRut, $rutEmisor);

        return new ResultadoEnvio((string) $r['trackId'], count($documentos));
    }

    /** Sobre firmado (EnvioDTE o EnvioBOLETA) sin subirlo: útil para guardarlo o enviarlo por otro medio. @param DocumentoEmitido[] $documentos */
    public function sobre(array $documentos): \DOMDocument
    {
        if ($documentos === []) {
            throw new InvalidArgumentException('No hay documentos para enviar.');
        }
        $boletas = $documentos[0]->esBoleta();
        foreach ($documentos as $d) {
            if ($d->esBoleta() !== $boletas) {
                throw new InvalidArgumentException('Las boletas (39 y 41) van en un envío aparte del resto de los documentos.');
            }
        }
        $caratula = [
            'rutEmisor' => Rut::toDteFormat($this->emisor->rut),
            'rutEnvia' => $this->certificado->subjectRut() ?? throw new RuntimeException('El certificado no trae el RUT del titular (serialNumber).'),
            'fechaResolucion' => $this->resolucion['fecha'],
            'numeroResolucion' => $this->resolucion['numero'],
        ];
        $xmls = array_map(static fn (DocumentoEmitido $d): \DOMDocument => $d->xml, array_values($documentos));
        $builder = new EnvioDteBuilder();

        return $boletas ? $builder->buildBoleta($xmls, $caratula, $this->certificado) : $builder->build($xmls, $caratula, $this->certificado);
    }

    /** Estado de un envío (para facturas y demás; las boletas usan {@see estadoEnvioBoleta()}). */
    public function estadoEnvio(string $trackId): array
    {
        $token = $this->tokenDte ??= $this->sii->authenticate($this->certificado);

        return $this->sii->getUploadStatus(Rut::toDteFormat($this->emisor->rut), $trackId, $token);
    }

    public function estadoEnvioBoleta(string $trackId): array
    {
        return $this->sii->getBoletaUploadStatus(Rut::toDteFormat($this->emisor->rut), $trackId, $this->tokenBoleta ??= $this->sii->authenticateBoleta($this->certificado));
    }
}
