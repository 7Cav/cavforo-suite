<?php

namespace Cav7\Citations\Template;

/**
 * The starting pay grade list. It matches on title so a rank_id that drifted can't mislabel
 * a rank, and a title not listed here stays blank. Recruit, Reservist and Tester have none.
 */
final class PayGradeSeed
{
    private const BY_TITLE = [
        'General of the Army' => ['O-10', 'GOA'],
        'General' => ['O-10', 'GEN'],
        'Lieutenant General' => ['O-9', 'LTG'],
        'Major General' => ['O-8', 'MG'],
        'Brigadier General' => ['O-7', 'BG'],
        'Colonel' => ['O-6', 'COL'],
        'Lieutenant Colonel' => ['O-5', 'LTC'],
        'Major' => ['O-4', 'MAJ'],
        'Captain' => ['O-3', 'CPT'],
        'First Lieutenant' => ['O-2', '1LT'],
        'Second Lieutenant' => ['O-1', '2LT'],
        'Chief Warrant Officer 5' => ['W-5', 'CW5'],
        'Chief Warrant Officer 4' => ['W-4', 'CW4'],
        'Chief Warrant Officer 3' => ['W-3', 'CW3'],
        'Chief Warrant Officer 2' => ['W-2', 'CW2'],
        'Warrant Officer 1' => ['W-1', 'WO1'],
        'Command Sergeant Major' => ['E-9', 'CSM'],
        'Sergeant Major' => ['E-9', 'SGM'],
        'First Sergeant' => ['E-8', '1SG'],
        'Master Sergeant' => ['E-8', 'MSG'],
        'Sergeant First Class' => ['E-7', 'SFC'],
        'Staff Sergeant' => ['E-6', 'SSG'],
        'Sergeant' => ['E-5', 'SGT'],
        'Corporal' => ['E-4', 'CPL'],
        'Specialist' => ['E-4', 'SPC'],
        'Private First Class' => ['E-3', 'PFC'],
        'Private' => ['E-2', 'PVT'],
    ];

    /**
     * @param array<int, string> $ranks Rank id => title.
     * @return list<array{rank_id: int, pay_grade: string, abbreviation: string}>
     */
    public static function rows(array $ranks): array
    {
        $rows = [];
        foreach ($ranks as $rankId => $title) {
            if (isset(self::BY_TITLE[$title])) {
                [$payGrade, $abbreviation] = self::BY_TITLE[$title];
                $rows[] = ['rank_id' => $rankId, 'pay_grade' => $payGrade, 'abbreviation' => $abbreviation];
            }
        }
        return $rows;
    }
}
