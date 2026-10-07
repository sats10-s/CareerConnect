@php
    $total = array_sum(array_column($classes, 'total_students'));
    $placed = array_sum(array_column($classes, 'students_placed'));
    $applied = array_sum(array_column($classes, 'students_applied'));
@endphp
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937;">
    <p>Placement summary for <strong>{{ $department->name }}</strong>, {{ $weekLabel }}.</p>

    <p>
        {{ $placed }} of {{ $total }} students placed ({{ $total > 0 ? round($placed / $total * 100, 1) : 0 }}%).
        {{ $applied }} have applied at least once.
        This week: {{ $newSelections }} new {{ $newSelections === 1 ? 'selection' : 'selections' }} and {{ $newApplications }} new {{ $newApplications === 1 ? 'application' : 'applications' }}.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; border: 1px solid #e5e7eb;">
        <thead style="background: #f1f5f9;">
            <tr>
                <th align="left">Year</th>
                <th align="left">Section</th>
                <th align="right">Students</th>
                <th align="right">Applied</th>
                <th align="right">Placed</th>
                <th align="right">Unplaced</th>
                <th align="right">Placed %</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($classes as $row)
                <tr style="border-top: 1px solid #e5e7eb;">
                    <td>{{ $row['year'] ?? '—' }}</td>
                    <td>{{ $row['section'] ?? '—' }}</td>
                    <td align="right">{{ $row['total_students'] }}</td>
                    <td align="right">{{ $row['students_applied'] }}</td>
                    <td align="right">{{ $row['students_placed'] }}</td>
                    <td align="right">{{ $row['students_unplaced'] }}</td>
                    <td align="right">{{ $row['placement_rate'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>The section-wise report and the list of unplaced students are attached as Excel files.</p>
    <p style="color: #6b7280; font-size: 12px;">Sent weekly by {{ $institution }} CareerConnect. The Super Admin can turn this off in Settings or change the HOD email under Departments.</p>
</div>
