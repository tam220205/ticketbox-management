<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Event extends Model
{
    use GeneratesUniqueSlug, HasFactory;

    protected $table = 'events';

    protected $fillable = [
        'type',
        'title',
        'slug',
        'description',
        'poster_path',
        'banner_path',
        'category_id',
        'duration_minutes',
        'status',
        'release_date',
        'is_seated',
        'details',
    ];

    protected $casts = [
        'release_date' => 'date',
        'duration_minutes' => 'integer',
        'is_seated' => 'boolean',
        'details' => 'array',
    ];

    /** Các key trong cột JSON `details`, đọc được như thuộc tính thường: $event->venue_name */
    public const DETAIL_KEYS = [
        'venue_name', 'venue_address', 'venue_gates', 'parking_info',
        'participants_title', 'participants_summary', 'host_mc', 'special_guests',
        'timeline', 'lineup', 'organizers', 'entry_policy',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($event) {
            if (empty($event->slug)) {
                $event->slug = static::uniqueSlug($event->title);
            }
        });
    }

    public function getAttribute($key)
    {
        if (in_array($key, self::DETAIL_KEYS, true)) {
            return $this->details[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    /** Tên cũ các view đang dùng. */
    public function getIsSeatedConcertAttribute(): bool
    {
        return (bool) $this->is_seated;
    }

    /**
     * Giá cơ bản thấp nhất trong các suất diễn (hiển thị "Từ ...₫").
     * Controller danh sách nên gọi withMin('showtimes', 'base_price') để tránh N+1.
     */
    public function getBasePriceAttribute(): ?int
    {
        $min = array_key_exists('showtimes_min_base_price', $this->attributes)
            ? $this->attributes['showtimes_min_base_price']
            : $this->showtimes()->min('base_price');

        return $min === null ? null : (int) $min;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeWithSalesStatus(Builder $query): Builder
    {
        $now = now();

        return $query->withExists([
            'showtimes as has_upcoming_showtimes' => fn (Builder $showtimes) => $showtimes->where('start_time', '>', $now),
            'showtimes as has_ongoing_showtimes' => fn (Builder $showtimes) => $showtimes
                ->where('start_time', '<=', $now)
                ->where('end_time', '>', $now),
            'showtimes as has_ended_showtimes' => fn (Builder $showtimes) => $showtimes->where(function (Builder $ended) use ($now) {
                $ended->where('end_time', '<=', $now)
                    ->orWhere(fn (Builder $withoutEnd) => $withoutEnd
                        ->whereNull('end_time')
                        ->where('start_time', '<=', $now));
            }),
        ]);
    }

    public function getSalesStatusAttribute(): string
    {
        if ($this->status === 'archived') {
            return 'archived';
        }
        if ($this->status !== 'published') {
            return 'draft';
        }
        if ($this->hasUpcomingShowtimes()) {
            return 'on_sale';
        }
        if ($this->hasOngoingShowtimes()) {
            return 'ongoing';
        }
        if ($this->hasEndedShowtimes()) {
            return 'ended';
        }

        return 'unscheduled';
    }

    public function hasUpcomingShowtimes(): bool
    {
        return $this->scheduleFlag('has_upcoming_showtimes', fn () => $this->showtimes()->where('start_time', '>', now())->exists(), fn ($showtime) => $showtime->start_time?->isFuture());
    }

    public function hasOngoingShowtimes(): bool
    {
        return $this->scheduleFlag('has_ongoing_showtimes', fn () => $this->showtimes()
            ->where('start_time', '<=', now())->where('end_time', '>', now())->exists(), fn ($showtime) => $showtime->start_time?->isPast() && $showtime->end_time?->isFuture());
    }

    public function hasEndedShowtimes(): bool
    {
        return $this->scheduleFlag('has_ended_showtimes', fn () => $this->showtimes()->where(function (Builder $ended) {
            $ended->where('end_time', '<=', now())
                ->orWhere(fn (Builder $withoutEnd) => $withoutEnd->whereNull('end_time')->where('start_time', '<=', now()));
        })->exists(), fn ($showtime) => $showtime->end_time
            ? $showtime->end_time->isPast()
            : $showtime->start_time?->isPast());
    }

    private function scheduleFlag(string $attribute, callable $query, callable $loadedRelation): bool
    {
        if (array_key_exists($attribute, $this->attributes)) {
            return (bool) $this->attributes[$attribute];
        }

        if ($this->relationLoaded('showtimes')) {
            return $this->showtimes->contains(fn (Showtime $showtime) => (bool) $loadedRelation($showtime));
        }

        return (bool) $query();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function showtimes(): HasMany
    {
        return $this->hasMany(Showtime::class, 'event_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'event_id');
    }

    public function getPosterUrlAttribute(): string
    {
        if ($this->poster_path) {
            if (str_starts_with($this->poster_path, 'http://') || str_starts_with($this->poster_path, 'https://')) {
                $driveFileId = $this->extractGoogleDriveFileId($this->poster_path);
                if ($driveFileId !== null) {
                    // The proxy route is stable per event; version by Drive ID so browsers
                    // fetch the replacement immediately instead of reusing cached bytes.
                    return route('events.poster', $this).'?v='.rawurlencode($driveFileId);
                }

                return $this->poster_path;
            }
            if (Storage::disk('public')->exists($this->poster_path)) {
                return Storage::url($this->poster_path);
            }
            if (str_starts_with($this->poster_path, 'storage/') || str_starts_with($this->poster_path, '/storage/')) {
                return asset(ltrim($this->poster_path, '/'));
            }
            if (file_exists(public_path($this->poster_path))) {
                return asset($this->poster_path);
            }
        }
        return "https://picsum.photos/seed/{$this->slug}/480/720";
    }

    private function extractGoogleDriveFileId(string $url): ?string
    {
        $parts = parse_url($url);
        if (! in_array(strtolower((string) ($parts['host'] ?? '')), ['drive.google.com', 'docs.google.com'], true)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $fileId = $query['id'] ?? null;

        if (! is_string($fileId) && preg_match('~/(?:file/)?d/([A-Za-z0-9_-]+)(?:/|$)~', (string) ($parts['path'] ?? ''), $matches)) {
            $fileId = $matches[1];
        }

        return is_string($fileId) && preg_match('/^[A-Za-z0-9_-]+$/', $fileId) ? $fileId : null;
    }

    public function favoritedByUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_user_favorites', 'event_id', 'user_id')
            ->withTimestamps();
    }

    public function isFavoritedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->favoritedByUsers()->where('users.id', $user->id)->exists();
    }
}
