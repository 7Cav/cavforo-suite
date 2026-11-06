<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Render\FontCatalog;
use Cav7\Citations\Render\VersionRules;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Phrase;
use XF\PrintableException;
use XF\Service\AbstractService;

/** Moves a version between states. None of it reaches an issued citation. */
class Publication extends AbstractService
{
    /** @return list<Phrase> Every refusal, so the page can show them all at once. */
    public function problems(TemplateVersion $draft): array
    {
        $billetIds = array_map('intval', $this->db()->fetchAllColumn('SELECT billet_id FROM xf_cav7_citation_signing_billet'));

        $problems = VersionRules::publishProblems(
            $draft->renderVersion(),
            $draft->plate_width,
            $draft->plate_height,
            $draft->Kinds->count(),
            $billetIds,
            $this->earlierScopes($draft),
        );

        return array_map(
            fn (array $problem): Phrase => \XF::phrase("cav7_cit_refused_{$problem['rule']}", ['field' => $problem['field']]),
            $problems
        );
    }

    /** @throws PrintableException */
    public function publish(TemplateVersion $draft, int $userId): void
    {
        $problems = $this->problems($draft);
        if ($problems !== []) {
            throw new PrintableException($problems);
        }

        $draft->bulkSet([
            'font_hashes' => json_encode($this->pinFonts($draft), JSON_THROW_ON_ERROR),
            'state' => VersionLifecycle::PUBLISHED,
            'publish_user_id' => $userId,
            'publish_date' => \XF::$time,
        ]);
        $draft->save();
    }

    public function retire(TemplateVersion $version): void
    {
        $version->bulkSet(['state' => VersionLifecycle::RETIRED, 'retire_date' => \XF::$time]);
        $version->save();
    }

    public function unretire(TemplateVersion $version): void
    {
        $version->bulkSet(['state' => VersionLifecycle::PUBLISHED, 'retire_date' => 0]);
        $version->save();
    }

    /** @return array<string, string> Font key => SHA-256, for the fonts this version uses. */
    private function pinFonts(TemplateVersion $draft): array
    {
        $files = FileStore::forApp($this->app);
        $catalog = FontCatalog::paths();

        $hashes = [];
        foreach ($draft->renderVersion()->fontKeys() as $key) {
            $hashes[$key] = $files->put($catalog[$key], 'font');
        }
        return $hashes;
    }

    /** @return array<string, string> Input key => scope. */
    private function earlierScopes(TemplateVersion $draft): array
    {
        $scopes = [];
        foreach ($draft->Template->Versions as $version) {
            if ($version->version_number < $draft->version_number) {
                foreach ($version->renderVersion()->inputs as $input) {
                    $scopes[$input->key] = $input->scope;
                }
            }
        }
        return $scopes;
    }
}
