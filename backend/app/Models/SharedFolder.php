<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ファイル共有のフォルダ（親子で階層）。user_id は生徒（データの持ち主） */
class SharedFolder extends Model
{
    protected $fillable = ['user_id', 'parent_id', 'name', 'created_by'];

    protected static function booted(): void
    {
        // フォルダ削除時は中のファイル・サブフォルダも削除する（DB は cascade。ファイル本体は SharedFile::deleting で消す）
        static::deleting(function (SharedFolder $folder) {
            $folder->files()->get()->each->delete();
            $folder->children()->get()->each->delete();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SharedFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SharedFolder::class, 'parent_id')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SharedFile::class, 'folder_id')->orderBy('name');
    }

    /** ルートからこのフォルダまでの道（自分を含む） */
    public function breadcrumbs(): array
    {
        $out = [];
        $f = $this;
        $guard = 0;
        while ($f !== null && $guard++ < 50) {
            array_unshift($out, ['id' => $f->id, 'name' => $f->name]);
            $f = $f->parent;
        }

        return $out;
    }

    /** 子孫フォルダの ID（自分を含む。移動先の検証用） */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier !== []) {
            $frontier = SharedFolder::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
