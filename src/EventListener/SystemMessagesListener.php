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

        foreach (\array_slice($blocked, 0, 20, true) as $path => $reasons) {
            $details = implode('', array_map(static fn (string $r): string => '<li>'.StringUtil::specialchars(ScanReason::format($r)).'</li>', $reasons));
            $items .= '<li><code>'.StringUtil::specialchars((string) $path).'</code><ul style="margin:2px 0 6px 18px;list-style:circle">'.$details.'</ul></li>';
        }

        if (\count($blocked) > 20) {
            $items .= '<li>…</li>';
        }

        return $html.'<p class="tl_error">'.StringUtil::specialchars(\sprintf($lang['systemMessage'] ?? '%d PDF file(s) were blocked by the security check and will not be processed as images. Please review and delete them if necessary:', \count($blocked))).'</p><ul class="tl_listing" style="margin:0 0 12px 18px;list-style:disc">'.$items.'</ul>'
            .'<p class="tl_info">'.StringUtil::specialchars($lang['systemHint'] ?? 'Harmless files can be approved after review on the command line: vendor/bin/contao-console contao-pdf-preview:scan --approve <file>').'</p>';
    }

    private function renderHostingProblems(): string
    {
        $problems = $this->hostingCheck?->getProblems() ?? [];

        if (!$problems) {
            return '';
        }

        $items = '';
        $class = 'tl_info';

        foreach ($problems as $problem) {
            $text = HostingCheck::describe($problem);
            $items .= '<li><strong>'.StringUtil::specialchars($text['label']).': '.StringUtil::specialchars($text['value']).'</strong> – '.StringUtil::specialchars($text['recommendation']).'</li>';

            if (HostingCheck::ERROR === $problem['status']) {
                $class = 'tl_error';
            }
        }

        return '<p class="'.$class.'">'.StringUtil::specialchars(HostingCheck::text('summary')).'</p>'
            .'<ul class="tl_listing" style="margin:0 0 6px 18px;list-style:disc">'.$items.'</ul>'
            .'<p class="tl_info" style="margin-bottom:12px">'.StringUtil::specialchars(HostingCheck::text('details')).'</p>';
    }
}
