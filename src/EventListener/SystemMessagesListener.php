<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use Dud\ContaoPdfPreview\Hosting\HostingCheck;
use Dud\ContaoPdfPreview\Security\BlockedPdfRegistry;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Zeigt Administratoren auf der Backend-Startseite, wenn im Dateiverzeichnis
 * blockierte PDFs liegen (z. B. per FTP hochgeladen).
 */
class SystemMessagesListener
{
    public function __construct(
        private readonly BlockedPdfRegistry $registry,
        private readonly Security $security,
        private readonly HostingCheck|null $hostingCheck = null,
    ) {
    }

    #[AsHook('getSystemMessages')]
    public function __invoke(): string
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return '';
        }

        $html = $this->renderHostingProblems();
        $blocked = $this->registry->getBlocked();

        if (!$blocked) {
            return $html;
        }

        $lang = $GLOBALS['TL_LANG']['dud_pdf_preview'] ?? [];
        $items = '';

        // Je Eintrag ein einzelnes Inline-Element: Das Backend stellt Listen in den
        // Hinweisen teils als Flexbox dar, die Leerzeichen zwischen Elementen entfernt
        foreach (\array_slice($blocked, 0, 20, true) as $path => $reasons) {
            $items .= '<li><span><code>'.StringUtil::specialchars((string) $path).'</code>: '.StringUtil::specialchars(ScanReason::formatAll($reasons)).'</span></li>';
        }

        if (\count($blocked) > 20) {
            $items .= '<li>…</li>';
        }

        $intro = 1 === \count($blocked)
            ? $lang['systemMessageOne'] ?? 'No preview image is created for 1 PDF file because of a finding of the security check. Downloading the file is not affected.'
            : \sprintf($lang['systemMessage'] ?? 'No preview images are created for %d PDF files because of a finding of the security check. Downloading the files is not affected.', \count($blocked));

        return $html.'<p class="tl_error">'.StringUtil::specialchars($intro).'</p>'
            .'<ul class="tl_listing" style="margin:0 0 6px 18px;list-style:disc">'.$items.'</ul>'
            .'<p class="tl_info" style="margin-bottom:12px">'.StringUtil::specialchars($lang['systemHint'] ?? 'Approval after review: vendor/bin/contao-console contao-pdf-preview:scan <file> --approve').'</p>';
    }

    /**
     * Nur echte Probleme, die die PDF-Vorschau verhindern. Alles Weitere zeigt die
     * Dateiverwaltung bzw. contao-pdf-preview:check.
     */
    private function renderHostingProblems(): string
    {
        $problems = $this->hostingCheck?->getProblems(errorsOnly: true) ?? [];

        if (!$problems) {
            return '';
        }

        $items = '';

        foreach ($problems as $problem) {
            $text = HostingCheck::describe($problem);
            $items .= '<li><span><strong>'.StringUtil::specialchars($text['label']).':</strong> '.StringUtil::specialchars($text['recommendation']).'</span></li>';
        }

        return '<p class="tl_error">'.StringUtil::specialchars(HostingCheck::text('summary')).'</p>'
            .'<ul class="tl_listing" style="margin:0 0 12px 18px;list-style:disc">'.$items.'</ul>';
    }
}
