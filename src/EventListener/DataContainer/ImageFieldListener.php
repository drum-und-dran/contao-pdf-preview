<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\Validator;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Component\Filesystem\Path;

/**
 * Erlaubt PDF-Dateien in allen Bild-Auswahlfeldern des Backends.
 *
 * Erkannt werden alle "fileTree"-Felder, deren erlaubte Endungen den
 * Bild-Endungen von Contao entsprechen (%contao.image.valid_extensions%) – egal
 * ob Core (News, Events, FAQ, Inhaltselemente, Module, Seiten …) oder
 * Erweiterungen von Drittanbietern. Felder, die Contao erst zur Laufzeit per
 * load_callback umstellt (z. B. tl_content.singleSRC je nach Elementtyp), werden
 * über einen zusätzlichen load_callback ebenfalls erfasst.
 */
class ImageFieldListener
{
    private const PLACEHOLDER = '%contao.image.valid_extensions%';

    private const MARKER = 'dudPdfPreview';

    /**
     * @param list<string> $extensionsWithPdf
     * @param list<string> $imageExtensions
     * @param list<string> $excludedFields
     */
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
        private readonly array $extensionsWithPdf,
        private readonly array $imageExtensions,
        private readonly bool $enabled,
        private readonly array $excludedFields,
    ) {
    }

    #[AsHook('loadDataContainer', priority: -255)]
    public function onLoadDataContainer(string $table): void
    {
        if (!$this->enabled || !\is_array($GLOBALS['TL_DCA'][$table]['fields'] ?? null)) {
            return;
        }

        foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $name => &$field) {
            if (!\is_array($field) || 'fileTree' !== ($field['inputType'] ?? null) || $this->isExcluded($table, (string) $name)) {
                continue;
            }

            $field['eval'] ??= [];
            $this->extend($field['eval']);

            // Nach den vorhandenen load_callbacks ausführen
            $field['load_callback'] ??= [];
            $field['load_callback'][] = [self::class, 'onLoadField'];
            $field['save_callback'] ??= [];
            $field['save_callback'][] = [self::class, 'onSaveField'];
        }

        unset($field);
    }

    public function onLoadField(mixed $value, DataContainer|null $dc = null): mixed
    {
        if (!$dc || !isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field])) {
            return $value;
        }

        $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['eval'] ??= [];
        $eval = &$GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['eval'];

        // Inhaltselement „PDF-Seiten“: nur PDFs auswählbar
        if ('tl_content' === $dc->table && 'singleSRC' === $dc->field && 'pdf_pages' === $this->getRecordValue($dc, 'type')) {
            $eval['extensions'] = 'pdf';
            $eval[self::MARKER] = true;

            return $value;
        }

        $this->extend($eval);

        return $value;
    }

    /**
     * Verhindert, dass im Backend unsichere PDFs als Bild ausgewählt werden.
     */
    public function onSaveField(mixed $value, DataContainer|null $dc = null): mixed
    {
        if (!$dc || empty($GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['eval'][self::MARKER]) || !$this->scanner->isEnabled()) {
            return $value;
        }

        $uuids = array_filter((array) StringUtil::deserialize($value, true));

        if (!$uuids) {
            return $value;
        }

        $this->framework->initialize();

        $validator = $this->framework->getAdapter(Validator::class);
        $uuids = array_values(array_filter($uuids, static fn ($uuid) => \is_string($uuid) && ($validator->isBinaryUuid($uuid) || $validator->isStringUuid($uuid))));

        if (!$uuids) {
            return $value;
        }

        $models = $this->framework->getAdapter(FilesModel::class)->findMultipleByUuids($uuids);

        foreach ($models ?? [] as $model) {
            if ('file' !== $model->type || 'pdf' !== strtolower((string) $model->extension)) {
                continue;
            }

            $result = $this->scanner->scan(Path::join($this->projectDir, $model->path));

            if (!$result->safe) {
                throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['ERR']['dudPdfUnsafe'] ?? 'The PDF file "%s" was blocked by the security check: %s', $model->path, ScanReason::formatAll($result->reasons)));
            }
        }

        return $value;
    }

    private function getRecordValue(DataContainer $dc, string $key): mixed
    {
        try {
            $record = method_exists($dc, 'getCurrentRecord') ? $dc->getCurrentRecord() : null;
        } catch (\Throwable) {
            $record = null;
        }

        if (\is_array($record)) {
            return $record[$key] ?? null;
        }

        $active = $dc->activeRecord ?? null;

        return $active->{$key} ?? null;
    }

    private function extend(array &$eval): void
    {
        if (!$this->isImageExtensionList($eval['extensions'] ?? null)) {
            return;
        }

        $eval['extensions'] = implode(',', $this->extensionsWithPdf);
        $eval[self::MARKER] = true;
    }

    private function isImageExtensionList(mixed $extensions): bool
    {
        if (self::PLACEHOLDER === $extensions) {
            return true;
        }

        if (\is_string($extensions)) {
            $extensions = StringUtil::trimsplit(',', $extensions);
        }

        if (!\is_array($extensions) || !$extensions) {
            return false;
        }

        $extensions = array_map('strtolower', $extensions);
        sort($extensions);

        $image = array_map('strtolower', $this->imageExtensions);
        sort($image);

        return $extensions === $image;
    }

    private function isExcluded(string $table, string $field): bool
    {
        // Das eigene Vorschaubild einer PDF darf selbst keine PDF sein
        if ('tl_files' === $table) {
            return true;
        }

        foreach ($this->excludedFields as $pattern) {
            if (fnmatch($pattern, $table.'.'.$field)) {
                return true;
            }
        }

        return false;
    }
}
