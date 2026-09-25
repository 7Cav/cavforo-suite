<?php
// PROTOTYPE fixture, throwaway. One BSM grant member, as the renderer would receive it
// from the grant, the grant member's snapshot, the signature and the template version.
// Member, operation, prose and signatory are all fabricated.

$short = 'For a single act demonstrating extraordinary heroism and skill under dangerous conditions '
    . 'while serving as Bear 1 in the 7th Cavalry Regiment during operation Midnight Snack near '
    . 'the kitchen on 08AUG26. During an assault on hunger, there were audible threats coming from '
    . 'the stomach and surrounding area. Private First Class John Sandwich along with Staff '
    . 'Sergeant Deli Meats took the initiative to conduct a two man assault on the kitchen. This '
    . 'assault led to the successful construction of a BLT sandwich which was a pivotal moment for '
    . 'the night. Private First Class Sandwich swiftly navigated squeaky floors and limited '
    . 'lighting, showing his skills as an infantryman to complete the objective with little regard '
    . 'for his personal sleep schedule. Private First Class John Sandwich’s skills and heroic '
    . 'actions reflect great credit upon himself and the 7th Cavalry Gaming Regiment.';

$long = $short . "\n"
    . 'Throughout the six weeks that followed, Private First Class Sandwich carried the same '
    . 'initiative into every rotation of Operation Midnight Snack, leading his fire team through '
    . 'three further assaults on the pantry and one on the refrigerator under a standing order of '
    . 'complete silence, and returning every member of his team to their bunks before reveille.' . "\n"
    . 'His planning, his calm under the threat of discovery and his care for the soldiers beside '
    . 'him set the standard for the troop, and are in keeping with the finest traditions of '
    . 'military service.';

// A deliberately oversized citation text, to see where the fitted size falls below legible.
$tail = $long . "\n" . str_repeat(
    'He kept the troop fed, rested and ready to move at a moment’s notice, and never once '
    . 'woke the First Sergeant. ', 6);

return [
    'texts' => ['short' => $short, 'long' => $long, 'tail' => $tail],
    'award' => 'BRONZE STAR MEDAL WITH VALOR DEVICE',
    'rank' => 'PRIVATE FIRST CLASS',
    'name' => 'JOHN SANDWICH',
    'given' => 'GIVEN UNDER MY HAND ON THIS 8TH DAY OF AUGUST, 2026 AT FORT HOOD, TEXAS',
    // one signature: an ink-only image plus the printed lines S1 types for it
    'signature' => [
        'ink' => 'ink.png',
        'lines' => ['JOHN Q. EXAMPLE', 'Colonel, Regimental Commander', '7th Cavalry Regiment'],
    ],
];
