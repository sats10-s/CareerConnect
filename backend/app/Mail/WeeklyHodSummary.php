<?php

namespace App\Mail;

use App\Models\Department;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One department's weekly placement summary for its HOD, with that
 * department's Section-wise Placement and Unplaced Student exports
 * attached. Built by App\Console\Commands\SendWeeklyHodSummary.
 */
class WeeklyHodSummary extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $classes  PlacementReportService::classBreakdown() rows
     * @param  array<string, string>  $files  filename => xlsx bytes
     */
    public function __construct(
        public readonly Department $department,
        public readonly string $institution,
        public readonly string $weekLabel,
        public readonly array $classes,
        public readonly int $newSelections,
        public readonly int $newApplications,
        private readonly array $files,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->department->code} placement summary, {$this->weekLabel}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.weekly-hod-summary');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (string $name) => Attachment::fromData(fn () => $this->files[$name], $name)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            array_keys($this->files),
        );
    }
}
