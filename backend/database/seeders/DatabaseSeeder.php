<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\Goal;
use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\StudyResource;
use App\Models\StudyResourceSection;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Vocabulary;
use App\Models\VocabularyAttempt;
use App\Models\VocabularyLearningStat;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** 科目別の達成率（デザインの RATES を移植） */
    private array $rates = [
        'math' => ['lec' => 0.46, 'quiz' => 0.36],
        'chem' => ['lec' => 0.27, 'quiz' => 0.18],
        'phys' => ['lec' => 0.20, 'quiz' => 0.13],
        'eng' => ['lec' => 0.50, 'quiz' => 0.38],
    ];

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'user@example.com'],
            ['name' => '山田 太郎', 'password' => Hash::make('password')]
        );

        UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => '山田 太郎',
                'school' => '○○大学 理工学部',
                'exam_date' => '2027-01-17',
                'default_type' => '問題集',
                'reminder' => true,
                'weekly_report' => true,
                'hide_empty' => false,
                'start_screen' => 'home',
            ]
        );

        $this->seedStudy($user);
        $this->seedResourceBooks($user);
        $this->seedVocabulary($user);
    }

    // ===================== 学習範囲・記録 =====================

    private function seedStudy(User $user): void
    {
        $json = json_decode(file_get_contents(database_path('data/study_data.json')), true);
        $colors = $json['colors'];
        $data = $json['data'];

        foreach ($data['subjects'] as $si => $subj) {
            $c = $colors[$subj['name']] ?? ['soft' => '#475569', 'vivid' => '#475569'];
            $subject = Subject::create([
                'user_id' => $user->id,
                'code' => $subj['id'],
                'name' => $subj['name'],
                'group_name' => $subj['group'],
                'color_soft' => $c['soft'],
                'color_vivid' => $c['vivid'],
                'sort_order' => $si,
            ]);
            $rate = $this->rates[$subj['id']] ?? ['lec' => 0.3, 'quiz' => 0.2];

            foreach ($subj['majors'] as $mi => $maj) {
                $major = MajorCategory::create([
                    'subject_id' => $subject->id,
                    'name' => $maj['name'],
                    'sort_order' => $mi,
                ]);
                $decay = max(0.16, 1 - $mi * 0.12);

                foreach ($maj['mids'] as $di => $midDef) {
                    $mid = MidCategory::create([
                        'major_category_id' => $major->id,
                        'name' => $midDef['name'],
                        'sort_order' => $di,
                    ]);

                    foreach ($midDef['subs'] as $li => $subName) {
                        StudyItem::create([
                            'mid_category_id' => $mid->id,
                            'name' => $subName,
                            'sort_order' => $li,
                            'included' => true,
                        ]);
                    }
                }
            }
        }
    }

    // ===================== 個別学習一覧（教材） =====================

    /**
     * 教材データのシード。問題集「Focus Gold 数学I+数学A」（xlsx由来の322行）のみを投入する。
     * 学習記録は生成しない（達成率はユーザーの登録に応じて0から積み上がる）。
     */
    private function seedResourceBooks(User $user): void
    {
        // 学習項目を名前パスで索引化（小分類への紐づけ用）
        $byPath = [];
        $subjectMath = null;
        $items = StudyItem::with('mid.major.subject')
            ->whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $user->id))
            ->get();
        foreach ($items as $it) {
            $s = $it->mid->major->subject;
            $byPath[$s->name.'|'.$it->mid->major->name.'|'.$it->mid->name.'|'.$it->name] = $it;
            if ($s->name === '数学') {
                $subjectMath = $s;
            }
        }

        // 問題集: Focus Gold 数学I+数学A
        $fg = json_decode(file_get_contents(database_path('data/focus_gold_1a.json')), true);
        $fgBook = ResourceBook::create([
            'user_id' => $user->id,
            'type' => '問題集',
            'title' => $fg['title'],
            'subject_id' => $subjectMath?->id,
            'sort_order' => 0,
        ]);
        $sort = 0;
        foreach ($fg['items'] as $row) {
            $item = $byPath[$row['subject'].'|'.$row['major'].'|'.$row['mid'].'|'.$row['sub']] ?? null;
            ResourceBookItem::create([
                'resource_book_id' => $fgBook->id,
                'study_item_id' => $item?->id,
                'chapter' => $row['chapter'] ?: null,
                'seq_no' => $row['seqNo'] ?: null,
                'check_flag' => $row['check'] ?: null,
                'title' => $row['title'] ?: null,
                'difficulty' => $row['difficulty'] ?: null,
                'sort_order' => $sort++,
            ]);
        }
    }

    // ===================== 英単語 =====================

    private function seedVocabulary(User $user): void
    {
        $vd = json_decode(file_get_contents(database_path('data/vocab_data.json')), true);
        $today = Carbon::today();

        $resource = StudyResource::create([
            'user_id' => $user->id,
            'name' => $vd['resourceName'],
        ]);

        $labels = ['easy', 'normal', 'hard'];
        $profs = ['low', 'medium', 'high'];

        foreach ($vd['sections'] as $si => $sec) {
            $section = StudyResourceSection::create([
                'study_resource_id' => $resource->id,
                'name' => $sec['name'],
                'sort_order' => $si,
            ]);

            foreach ($sec['words'] as $wi => $w) {
                $label = $labels[$this->hashStr('lab'.$w['w']) % 3];
                $seed = $this->hashStr('v|'.$w['w']);
                $r = $this->rnd($seed);
                $r2 = $this->rnd($seed ^ 0x1234567);
                $r3 = $this->rnd($seed ^ 0x9e3779b9);

                $rep = 0;
                $interval = 0;
                $correct = 0;
                $incorrect = 0;
                $prof = 'low';
                $nextReview = null;
                $lastAttempted = null;
                $hasStat = true;

                if ($r < 0.42) {
                    $hasStat = false; // 新規（統計なし）
                } elseif ($r < 0.8) {
                    $rep = 1 + intval(floor($r2 * 2));
                    $interval = $rep === 1 ? 1 : 6;
                    $correct = 1 + intval(floor($r2 * 4));
                    $incorrect = intval(floor($r3 * 3));
                    $prof = $profs[intval(floor($r2 * 3))];
                    $off = intval(floor(($r2 - 0.45) * 9));
                    $nextReview = $today->copy()->addDays($off);
                    $lastAttempted = $today->copy()->subDays(max(1, 7 - $interval));
                } else {
                    $rep = 3 + intval(floor($r2 * 3));
                    $interval = 7 + intval(floor($r2 * 18));
                    $correct = 4 + intval(floor($r2 * 8));
                    $incorrect = intval(floor($r3 * 2));
                    $prof = 'high';
                    $off = 4 + intval(floor($r2 * 16));
                    $nextReview = $today->copy()->addDays($off);
                    $lastAttempted = $today->copy()->subDays(3);
                }

                $vocab = Vocabulary::create([
                    'study_resource_section_id' => $section->id,
                    'word' => $w['w'],
                    'meaning' => $w['m'],
                    'part_of_speech' => $w['pos'] ?? null,
                    'importance' => $w['imp'] ?? 1,
                    'label' => $label,
                    'proficiency' => $prof,
                    'example_sentence' => $w['ex'] ?? null,
                    'example_translation' => $w['exj'] ?? null,
                    'sort_order' => $wi,
                ]);

                if ($hasStat) {
                    VocabularyLearningStat::create([
                        'vocabulary_id' => $vocab->id,
                        'user_id' => $user->id,
                        'correct_count' => $correct,
                        'incorrect_count' => $incorrect,
                        'last_attempted_at' => $lastAttempted,
                        'next_review_at' => $nextReview,
                        'ease_factor' => 2.50,
                        'interval_days' => $interval,
                        'repetition_count' => $rep,
                    ]);

                    // 回答履歴（直近約30日に分散、復習・正答率機能のデータ）
                    $attempts = [];
                    for ($i = 0; $i < $correct; $i++) {
                        $attempts[] = $this->attemptRow($vocab->id, $user->id, true, $today, $i);
                    }
                    for ($i = 0; $i < $incorrect; $i++) {
                        $attempts[] = $this->attemptRow($vocab->id, $user->id, false, $today, $i + $correct);
                    }
                    if ($attempts) {
                        VocabularyAttempt::insert($attempts);
                    }
                }
            }
        }
    }

    private function attemptRow(int $vid, int $uid, bool $correct, Carbon $today, int $i): array
    {
        $daysAgo = ($i * 5 + ($correct ? 1 : 2)) % 28 + 1;
        $at = $today->copy()->subDays($daysAgo)->setTime(20, 0);

        return [
            'vocabulary_id' => $vid,
            'user_id' => $uid,
            'is_correct' => $correct,
            'quiz_type' => 'choice',
            'answered_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // ===================== JS互換 乱数ヘルパー =====================

    private function u32(int|float $n): int
    {
        $n = (int) fmod((float) $n, 4294967296.0);

        return $n & 0xFFFFFFFF;
    }

    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;
        $low = $al * $bl;
        $mid = (($ah * $bl) + ($al * $bh)) & 0xFFFFFFFF;

        return ($low + (($mid << 16) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    }

    /** FNV-1a 32bit（デザインの hashStr と一致, charCodeAt = UTF-16 code unit） */
    private function hashStr(string $s): int
    {
        $h = 2166136261;
        foreach ($this->utf16Units($s) as $code) {
            $h ^= $code;
            $h = $this->imul($h, 16777619);
        }

        return $h & 0xFFFFFFFF;
    }

    private function utf16Units(string $s): array
    {
        $utf16 = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $units = [];
        $len = strlen($utf16);
        for ($i = 0; $i < $len; $i += 2) {
            $units[] = ord($utf16[$i]) | (ord($utf16[$i + 1]) << 8);
        }

        return $units;
    }

    /** mulberry32（デザインの rnd と一致）, [0,1) を返す */
    private function rnd(int $seed): float
    {
        $t = ($seed & 0xFFFFFFFF) + 0x6D2B79F5;
        $a = $t & 0xFFFFFFFF;
        $t = $this->imul($a ^ ($a >> 15), $a | 1);
        $inner = $this->imul($t ^ ($t >> 7), $t | 61);
        $t = ($t ^ (($t + $inner) & 0xFFFFFFFF)) & 0xFFFFFFFF;

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
    }
}
