<?php

namespace App\Enums;

/**
 * Where a student report is in its processing:
 *
 * - Processing:       text extraction + anonymization queued or running.
 * - ExtractionFailed: no text could be read (scanned images, encrypted…); the
 *                     file is still stored. Psychopedagogy can add adjustments by hand.
 * - Ready:            text extracted; no AI reading (not requested, or switched off).
 * - AwaitingReview:   anonymized text waits for psychopedagogy to review and confirm the send.
 * - Interpreting:     confirmed and sent; waiting for the AI's proposals.
 * - Proposed:         proposals ready to review.
 * - Error:            the AI reading failed; psychopedagogy can retry or work by hand.
 */
enum ReportDocumentStatus: string
{
    case Processing = 'processing';
    case ExtractionFailed = 'extraction_failed';
    case Ready = 'ready';
    case AwaitingReview = 'awaiting_review';
    case Interpreting = 'interpreting';
    case Proposed = 'proposed';
    case Error = 'error';
}
