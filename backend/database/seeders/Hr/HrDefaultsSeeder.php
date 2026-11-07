<?php

namespace Database\Seeders\Hr;

use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Leave types under the Employment and Labour Relations Act (Tanzania) and the
 * fixed-date public holidays. Moving holidays (Easter, Eid) are added yearly by HR.
 */
class HrDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // code => [name, days/year, paid, carry forward, attachment, gender]
            'ANNUAL' => ['Annual leave', 28, true, 7, false, null],
            'SICK' => ['Sick leave', 63, true, 0, true, null],
            'MATERNITY' => ['Maternity leave', 84, true, 0, false, 'female'],
            'PATERNITY' => ['Paternity leave', 3, true, 0, false, 'male'],
            'COMPASSION' => ['Compassionate leave', 4, true, 0, false, null],
            'UNPAID' => ['Unpaid leave', 0, false, 0, false, null],
        ];
        foreach ($types as $code => [$name, $days, $paid, $carry, $attachment, $gender]) {
            LeaveType::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'days_per_year' => $days, 'is_paid' => $paid, 'max_carry_forward' => $carry,
                'requires_attachment' => $attachment, 'gender' => $gender, 'is_active' => true,
            ]);
        }

        $holidays = [
            '01-01' => "New Year's Day", '01-12' => 'Zanzibar Revolution Day', '04-07' => 'Karume Day',
            '04-26' => 'Union Day', '05-01' => "Workers' Day", '07-07' => 'Saba Saba', '08-08' => 'Nane Nane',
            '10-14' => 'Nyerere Day', '12-09' => 'Independence Day', '12-25' => 'Christmas Day', '12-26' => 'Boxing Day',
        ];
        $year = now()->year;
        foreach ($holidays as $md => $name) {
            Holiday::withTrashed()->firstOrCreate(['name' => $name, 'recurring' => true], ['date' => "{$year}-{$md}"]);
        }
    }
}
