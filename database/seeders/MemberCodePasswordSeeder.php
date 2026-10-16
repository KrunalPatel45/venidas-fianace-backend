<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MemberCodePasswordSeeder extends Seeder
{
    public function run(): void
    {
        $updated = 0;
        $skipped = 0;

        User::query()
            ->where('role', 'member')
            ->orderBy('id')
            ->chunkById(200, function ($members) use (
                &$updated,
                &$skipped
            ) {
                foreach ($members as $member) {

                    $memberCode = trim(
                        (string) $member->member_code
                    );

                    if ($memberCode === '') {
                        $skipped++;
                        continue;
                    }

                    // Hash member code before saving.
                    $member->password = Hash::make($memberCode);

                    $member->save();

                    $updated++;
                }
            });

        $this->command?->info(
            "Member passwords updated: {$updated}"
        );

        $this->command?->warn(
            "Members skipped (empty member code): {$skipped}"
        );

        Log::info('MemberCodePasswordSeeder completed', [
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }
}