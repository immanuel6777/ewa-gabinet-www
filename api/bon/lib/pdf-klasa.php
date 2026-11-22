<?php
// TCPDF bez niewidocznego dopisku „Powered by TCPDF” na dole strony (wczytywany przez lib/pdf.php po tcpdf.php).
declare(strict_types=1);

class BonTCPDF extends TCPDF {
    public function __construct(...$a) {
        parent::__construct(...$a);
        $this->tcpdflink = false;
    }
}
