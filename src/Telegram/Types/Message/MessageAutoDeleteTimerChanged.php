<?php

namespace SergiX44\Nutgram\Telegram\Types\Message;

use SergiX44\Nutgram\Telegram\Types\BaseType;

/**
 * This object represents a service message about a change in auto-delete timer settings.
 * @see https://core.telegram.org/bots/api#voicechatended
 */
class MessageAutoDeleteTimerChanged extends BaseType
{
    /**
     * New auto-delete time for messages in the chat
     */
    public int $message_auto_delete_time;
}