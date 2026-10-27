<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;

/** Documento ya construido, timbrado y firmado, listo para enviar al SII. */
final class DocumentoEmitido
{
    public function __construct(
        public readonly int $tipo,
        public readonly int $folio,
        public readonly int $montoTotal,
        public readonly string $fechaEmision,
        public readonly string $rutEmisor,
        public readonly string $rutReceptor,
        public readonly DOMDocument $xml,
        /** Bytes ISO-8859-1 del timbre (TED), los que se codifican en el PDF417 impreso. */
        public readonly string $ted,
    ) {
    }

    public function esBoleta(): bool
    {
        return DocumentType::isBoleta($this->tipo);
    }

    /** XML firmado tal como se transmite (ISO-8859-1). */
    public function toXml(): string
    {
        return (string) $this->xml->saveXML();
    }

    /** El timbre como código PDF417 en SVG, listo para incrustar en la representación impresa. */
    public function timbreSvg(): string
    {
        $encoder = new Pdf417Encoder();

        return $encoder->toSvg($encoder->encode($this->ted));
    }
}
