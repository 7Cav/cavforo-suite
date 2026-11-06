<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\InputDefinition;

/** What the template preview fills a design in with. It lives in the page and is never stored. */
final class SampleValues
{
    public const NAME = 'Sample Member';
    public const INPUT = 'Sample';
    public const TEXT = 'For meritorious achievement while serving as a squad leader. Sample Member kept the squad trained, '
        . 'informed and ready through a demanding season of operations, and the example set reflects great credit upon the squad.';
    public const LONG_TEXT = self::TEXT
        . "\nThrough a long and difficult campaign, Sample Member planned and led every training cycle the squad ran, wrote the "
        . 'standing orders the platoon came to rely on, and stayed on hand late into every operation to bring new members up to '
        . 'standard. When the platoon lost two of its leaders in the same month, Sample Member took on both of their duties '
        . 'without being asked, and kept each team fully manned, briefed and on time. Other squads began sending their newest '
        . 'members along to those sessions, and Sample Member made room for every one of them, staying behind after each '
        . 'operation to walk through what had gone well and what could be done better the next time.'
        . "\nThe results speak for themselves. Attendance across the platoon rose in each of the following months, every member "
        . 'of the squad completed their next course on the first attempt, and the squad was chosen to lead the closing operation '
        . 'of the season. Asked to take charge of that operation at a few hours of notice, Sample Member briefed the whole '
        . 'platoon, adjusted the plan twice as conditions changed, and brought every team home on schedule. Several members '
        . 'who trained under Sample Member have since gone on to lead squads of their own, carrying the same methods with them.'
        . "\nThis steady, selfless work, sustained over many months and never once asked for, stands as an example to every "
        . 'member of the regiment, and reflects great credit upon Sample Member, the squad and the unit as a whole.';

    /**
     * @param array<string, string> $inputs By key.
     * @param array<string, int> $signatures Signature ids by slot key.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $rankId,
        public readonly string $date,
        public readonly string $citationText,
        public readonly array $inputs,
        public readonly array $signatures,
    ) {
    }

    /**
     * A value posted blank stays blank, so S1 can see what a field does with nothing in it.
     *
     * @param array<string, mixed> $posted The page's `sample` fields, empty for the defaults.
     * @param list<InputDefinition> $inputs
     */
    public static function from(array $posted, array $inputs, string $today, int $defaultRankId): self
    {
        $values = [];
        foreach ($inputs as $input) {
            $values[$input->key] = (string) ($posted['inputs'][$input->key] ?? self::INPUT);
        }

        return new self(
            (string) ($posted['name'] ?? self::NAME),
            (int) ($posted['rank_id'] ?? $defaultRankId),
            (string) ($posted['date'] ?? $today),
            empty($posted['long']) ? (string) ($posted['text'] ?? self::TEXT) : self::LONG_TEXT,
            $values,
            array_map('intval', (array) ($posted['signatures'] ?? [])),
        );
    }
}
