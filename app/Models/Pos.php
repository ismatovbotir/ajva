<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Pos extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'shop_id',
    ];

    protected $hidden = [
        'api_token_hash',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * Issue a fresh API token for this POS terminal, storing only its
     * SHA-256 hash and returning the plaintext token. The plaintext is
     * never recoverable again after this call returns — same principle as
     * Laravel Sanctum's own token issuance.
     */
    public function issueApiToken(): string
    {
        $token = Str::random(40);

        $this->forceFill([
            'api_token_hash' => hash('sha256', $token),
        ])->save();

        return $token;
    }
}
