<?php

namespace Cav7\MilpacMention\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;

/**
 * The ACP picker for the ticket-category deny-list (issue #147): a multi-select of
 * real NF/Tickets categories, by name, so suppressing an award queue is a click
 * rather than an id an admin has to go and look up.
 *
 * The forum-node deny-list needs nothing like this. XF\Option\Forum::renderSelectMultiple
 * is core, and is the same renderer EWR/Porta's article-forums option rides. Ticket
 * categories have no equivalent: NF/Tickets ships three option renderers and all
 * three are single-select, none of them for categories. So this is ours, built on
 * the same XF\Option\AbstractOption shape theirs are.
 *
 * THE FIRST ACTIVE SOFT-DEPENDENCY CHECK. NF/Tickets is not a hard require, and
 * everywhere else in this add-on that fact is handled passively: the ticket
 * extensions declare an NF\Tickets from_class, so on a site without NF/Tickets that
 * class never loads, XF never builds the XFCP proxy, and the extensions never run.
 * Nothing of ours has to notice. This class is different. The ACP loads it to render
 * the options page whether or not NF/Tickets is installed, so it has to check for
 * itself before reaching for anything of theirs.
 *
 * When NF/Tickets is absent the row is rendered DISABLED with the reason attached,
 * rather than being dropped or left as an empty picker. Both of the easier options
 * mislead: a vanished row leaves an admin with no explanation for why a documented
 * setting is not there, and an empty but enabled picker asserts something false,
 * that the board has no ticket categories.
 */
class TicketCategory extends AbstractOption
{
    /**
     * The multi-select of ticket categories. A deny-list names several areas at
     * once, so this is the `multiple` variant; there is deliberately no single
     * -select twin, since suppressing exactly one category is not a shape anyone
     * asked for.
     *
     * @return string
     */
    public static function renderSelectMultiple(Option $option, array $htmlParams)
    {
        $controlOptions = static::getControlOptions($option, $htmlParams);
        $controlOptions['multiple'] = true;
        $controlOptions['size'] = 8;

        $rowOptions = static::getRowOptions($option, $htmlParams);

        // The active check comes first, before any NF/Tickets class is named, because
        // a guard that runs after the lookup guards nothing.
        if (!\XF::isAddOnActive('NF/Tickets'))
        {
            $controlOptions['disabled'] = true;
            $rowOptions['explain'] = static::appendMissingTicketsNotice($rowOptions['explain'] ?? '');

            return static::getTemplater()->formSelectRow($controlOptions, [], $rowOptions);
        }

        return static::getTemplater()->formSelectRow($controlOptions, static::getCategoryChoices(), $rowOptions);
    }

    /**
     * Every ticket category as a select choice, in the board's own tree order with
     * child categories indented, which is how an admin recognises them. Mirrors
     * XF\Option\Forum's node list: the same getCategoryOptionsData/getNodeOptionsData
     * pair on the category-tree repository, and the same pre-escaping of the label,
     * so a category title carrying markup renders as the literal title.
     *
     * No "(none)" entry: getCategoryOptionsData is asked for the list without its
     * empty choice, because a deny-list has nothing to say about the absence of a
     * category.
     *
     * @return array
     */
    protected static function getCategoryChoices()
    {
        $choices = \XF::repository('NF\Tickets:Category')->getCategoryOptionsData(false);

        return array_map(function ($choice)
        {
            $choice['label'] = \XF::escapeString($choice['label']);
            return $choice;
        }, $choices);
    }

    /**
     * The option's own explain text with the "NF/Tickets is not installed" reason
     * appended, so the disabled control says why it is disabled instead of just
     * sitting there greyed out. The phrase is escaped because it lands in an HTML
     * explain block.
     *
     * @param string $explainHtml
     *
     * @return string
     */
    protected static function appendMissingTicketsNotice($explainHtml)
    {
        $notice = \XF::escapeString((string) \XF::phrase('cav7_mm_option_ticket_categories_no_tickets'));
        $explainHtml = (string) $explainHtml;

        return $explainHtml !== '' ? $explainHtml . '<br />' . $notice : $notice;
    }
}
