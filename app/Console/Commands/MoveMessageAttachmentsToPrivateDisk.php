<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves message attachments that fell back to local storage off the public
 * disk and onto the private one.
 *
 * Files on the public disk sit under storage/app/public, which the
 * public/storage symlink exposes to the web server, so they could be fetched
 * directly without passing ConsultationMessageController::downloadAttachment()
 * and its authorization check. Cloudinary-hosted attachments are unaffected —
 * their file_path is an absolute URL and is never touched here.
 *
 * The stored path is disk-relative and identical on both disks, so nothing in
 * the database has to change: only which disk the path resolves against, which
 * the controller now decides. That is also why this command is safe to re-run —
 * an attachment already on the private disk with no public copy left is simply
 * reported as already migrated.
 */
class MoveMessageAttachmentsToPrivateDisk extends Command
{
    protected $signature = 'attachments:move-to-private
                            {--dry-run : Report what would change without copying or deleting anything}';

    protected $description = 'Move locally-stored message attachments from the public disk to the private medical disk';

    private const PUBLIC_DISK = 'public';

    private const PRIVATE_DISK = 'message_attachments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no files will be copied or deleted.');
        }

        // Cloudinary rows store an absolute URL. This is the same test the
        // controller uses to decide how to serve an attachment, so the two can
        // never disagree about which rows are local.
        $localAttachments = MessageAttachment::query()
            ->where('file_path', 'not like', 'http%')
            ->orderBy('attachment_id')
            ->get();

        $cloudinaryCount = MessageAttachment::query()->where('file_path', 'like', 'http%')->count();

        $this->info(sprintf(
            'Found %d local attachment(s) to consider; %d Cloudinary attachment(s) will not be touched.',
            $localAttachments->count(),
            $cloudinaryCount
        ));

        $migrated = 0;
        $skipped = 0;
        $failed = 0;
        $orphanedPublicCopies = 0;

        foreach ($localAttachments as $attachment) {
            $path = (string) $attachment->file_path;
            $onPublic = Storage::disk(self::PUBLIC_DISK)->exists($path);
            $onPrivate = Storage::disk(self::PRIVATE_DISK)->exists($path);

            if (! $onPublic && $onPrivate) {
                $this->line("  [skip]    #{$attachment->attachment_id} already on the private disk");
                $skipped++;

                continue;
            }

            if (! $onPublic && ! $onPrivate) {
                // Never silently pass over a missing file: the row points at
                // bytes that are on neither disk and needs a human to look.
                $this->error("  [missing] #{$attachment->attachment_id} source file not found on either disk: {$path}");
                $failed++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [would]   #{$attachment->attachment_id} copy to private disk and remove public copy");
                $migrated++;

                continue;
            }

            // Copy, then verify, before anything destructive happens. If this
            // command dies at any point before the delete below, the public
            // copy is still intact and the run can simply be repeated.
            try {
                $sourceSize = Storage::disk(self::PUBLIC_DISK)->size($path);
                $stream = Storage::disk(self::PUBLIC_DISK)->readStream($path);

                if ($stream === false || $stream === null) {
                    throw new \RuntimeException('Unable to open the source file for reading.');
                }

                Storage::disk(self::PRIVATE_DISK)->writeStream($path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable $copyError) {
                $this->error("  [fail]    #{$attachment->attachment_id} copy failed: ".$copyError->getMessage());
                $failed++;

                continue;
            }

            if (! Storage::disk(self::PRIVATE_DISK)->exists($path)) {
                $this->error("  [fail]    #{$attachment->attachment_id} private copy missing after write; public copy left in place");
                $failed++;

                continue;
            }

            $destinationSize = Storage::disk(self::PRIVATE_DISK)->size($path);

            if ($destinationSize !== $sourceSize) {
                $this->error(sprintf(
                    '  [fail]    #%d size mismatch (source %d bytes, copy %d bytes); public copy left in place',
                    $attachment->attachment_id,
                    $sourceSize,
                    $destinationSize
                ));
                $failed++;

                continue;
            }

            // The stored path is disk-relative and identical on both disks, so
            // there is nothing to update on the row. It is re-checked here so a
            // future change to the path shape cannot slip through unnoticed.
            if ((string) $attachment->file_path !== $path) {
                $attachment->forceFill(['file_path' => $path])->save();
            }

            // Only now, with a verified private copy and a database row that
            // already points at it, is the exposed public copy removed.
            if (! Storage::disk(self::PUBLIC_DISK)->delete($path)) {
                $this->warn("  [partial] #{$attachment->attachment_id} migrated, but the public copy could not be deleted and is STILL WEB-ACCESSIBLE: {$path}");
                $orphanedPublicCopies++;
                $migrated++;

                continue;
            }

            $this->line("  [ok]      #{$attachment->attachment_id} migrated ({$sourceSize} bytes)");
            $migrated++;
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. migrated=%d skipped=%d failed=%d cloudinary_untouched=%d',
            $migrated,
            $skipped,
            $failed,
            $cloudinaryCount
        ));

        if ($orphanedPublicCopies > 0) {
            $this->error(sprintf(
                '%d file(s) remain readable from the public disk and must be removed manually.',
                $orphanedPublicCopies
            ));
        }

        return ($failed > 0 || $orphanedPublicCopies > 0) ? self::FAILURE : self::SUCCESS;
    }
}
