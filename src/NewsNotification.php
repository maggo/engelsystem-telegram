<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\News;

/**
 * New news go to everyone on Telegram when Engelsystem's "Send notification" is ticked, like its
 * e-mails. Only marked here; the scheduler sends them (Scheduler::newsPings()), once per person.
 */
class NewsNotification
{
    public function created(News $news, bool $sendNotification = true): void
    {
        if (!$sendNotification || !app(BotApi::class)->isConfigured()) {
            return;
        }

        // user_id 0: the news itself, sent_at once everyone has it
        Notification::query()->insertOrIgnore([
            'kind'          => Notification::NEWS,
            'shift_id'      => $news->id,
            'user_id'       => 0,
            'angel_type_id' => 0,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
        Roster::log(sprintf('News "%s" (%d) will be sent on Telegram', $news->title, $news->id));
    }
}
