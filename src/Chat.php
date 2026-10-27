<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Models\AngelType;
use Engelsystem\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram group linked to an area, or the coordination group if angel_type_id is null
 *
 * @property string         $chat_id
 * @property string|null    $title
 * @property int|null       $angel_type_id
 *
 * @property-read AngelType|null $angelType
 */
class Chat extends BaseModel
{
    public $table = 'telegram_chats'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    protected $primaryKey = 'chat_id'; // phpcs:ignore

    protected $keyType = 'string'; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    protected $casts = [ // phpcs:ignore
        'angel_type_id' => 'integer',
    ];

    protected $fillable = ['chat_id', 'title', 'angel_type_id']; // phpcs:ignore

    /**
     * @return Collection<Chat> the area's group chats, or the coordination chats for null
     */
    public static function forArea(?int $angelTypeId): Collection
    {
        return self::query()
            ->when($angelTypeId, fn($q) => $q->where('angel_type_id', $angelTypeId), fn($q) => $q->whereNull('angel_type_id'))
            ->get();
    }

    public function angelType(): BelongsTo
    {
        return $this->belongsTo(AngelType::class);
    }
}
