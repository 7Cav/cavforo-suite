<?php

namespace Cav7\TicketWebhook;

/**
 * The opener may not open a ticket in the hook's category: the category is
 * gone, closed for opening, or the opener has no create permission there.
 * The public route answers it with 403; every other failure to open is 500.
 */
class NotPermitted extends \RuntimeException
{
}
