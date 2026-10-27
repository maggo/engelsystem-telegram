<?php

declare(strict_types=1);

namespace Telegram\Plugin\Migrations;

use Engelsystem\Database\Migration\Migration;
use Engelsystem\Migrations\Reference;
use Illuminate\Database\Schema\Blueprint;

class CreateTelegramTables extends Migration
{
    use Reference;

    /**
     * Later releases only add (new tables, new nullable columns), so the previous release keeps
     * working on a newer schema and can be rolled back to
     */
    public function up(): void
    {
        // Telegram identity of an account: the website login (OIDC subject) and the bot chat
        // (the numeric Telegram user id), both set once the person connects. Until then the
        // account waits for its handle (import, reset link).
        $this->schema->create('telegram_accounts', function (Blueprint $table): void {
            $this->referencesUser($table, true);
            $table->string('telegram_id')->nullable()->unique();
            $table->string('chat_id')->nullable()->unique();
            $table->string('username')->nullable()->index();
            $table->timestamps();
        });

        $this->schema->create('telegram_checkins', function (Blueprint $table): void {
            $table->increments('id');
            $this->referencesUser($table);
            $table->date('day');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'day']);
        });

        // What the scheduler sends and how volunteers answered: one pre-shift ping per shift and
        // user, one roll call per shift and area. Claimed before sending, sent_at once it went
        // out. MySQL lets any number of NULLs through a unique key, so "none" is stored as 0.
        $this->schema->create('telegram_notifications', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kind');
            $table->unsignedInteger('shift_id');
            $table->unsignedInteger('user_id')->default(0);
            $table->unsignedInteger('angel_type_id')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('answer')->nullable();
            // The "why can't you make it?" question, so the reply finds the right shift
            $table->unsignedBigInteger('prompt_message_id')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'shift_id', 'user_id', 'angel_type_id'], 'telegram_notifications_once_unique');
        });

        // Group chats: one per area, plus the coordination group (angel_type_id null)
        $this->schema->create('telegram_chats', function (Blueprint $table): void {
            $table->string('chat_id')->primary();
            $table->string('title')->nullable();
            $this->references($table, 'angel_types')->nullable();
            $table->timestamps();
        });

        // Updates from Telegram, stored before they are acknowledged: a redelivered update is
        // ignored, and one whose handling was cut short is handled again by the scheduler
        $this->schema->create('telegram_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_id')->primary();
            $table->mediumText('payload');
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Telegram users the bot couldn't match, so each is logged only once a day
        $this->schema->create('telegram_unknown_contacts', function (Blueprint $table): void {
            $table->string('telegram_user_id');
            $table->date('day');
            $table->primary(['telegram_user_id', 'day']);
        });
    }

    public function down(): void
    {
        $this->schema->drop('telegram_unknown_contacts');
        $this->schema->drop('telegram_updates');
        $this->schema->drop('telegram_chats');
        $this->schema->drop('telegram_notifications');
        $this->schema->drop('telegram_checkins');
        $this->schema->drop('telegram_accounts');
    }
}
