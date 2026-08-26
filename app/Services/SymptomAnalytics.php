<?php

namespace App\Services;

/**
 * Aggregates `consultation_requests.symptoms_desc` — an unvalidated JSON
 * column, decoded to an array by Consultation's cast — into the symptom
 * analytics shown on the admin dashboard (Phase 1 §07 / Phase 2 AD-09..12).
 *
 * Deliberately pure PHP over already-fetched values, not SQL JSON
 * functions: JSON_TABLE requires MySQL 8+ and MariaDB (the XAMPP/dev
 * driver) doesn't support it at all, and the Pest suite runs SQLite, which
 * uses a different JSON dialect again. A SQL-side implementation would
 * therefore work in neither dev nor test. The caller is expected to filter
 * the relevant requests in the database first (see
 * DashboardAnalyticsService::forAdmin()) and hand this class only the
 * `symptoms_desc` column values — never full models.
 *
 * The caller is also responsible for excluding follow-up requests before
 * calling this. Both follow-up creation paths
 * (ConsultationOwnershipService::decideFollowUpByPhysician,
 * PhysicianController::createFollowUpConsultationFromSource) copy
 * `symptoms_desc` verbatim from the parent request, so a follow-up's
 * "symptoms" are not a new patient report — including them here would
 * double- or triple-count a single report every time that patient
 * received a follow-up.
 *
 * Contract: this class never throws on malformed input. A single
 * unparseable row is skipped and counted in `malformed_requests`; it can
 * never take down a dashboard.
 */
class SymptomAnalytics
{
    /** Custom symptom terms reported fewer than this many times are suppressed (k=3 privacy floor). */
    private const CUSTOM_TERM_MIN_REPORTS = 3;

    private const VALID_SEVERITIES = [1, 2, 3, 4];

    /** The severity value every symptom starts at the instant it's selected — not necessarily a deliberate choice. */
    private const DEFAULT_SEVERITY_BUCKET = 3;

    /**
     * The exact standardized symptom names offered by the intake form's
     * picker (resources/views/patient/newconsultation.blade.php, the
     * `x-for="symptom in [...]"` list) — excluding the literal 'Others'
     * entry, which is the UI trigger for free-text entry, not a symptom
     * name. This is the only authoritative vocabulary in the application;
     * there is no database table or config for it, so this list must be
     * kept in sync by hand if the intake form's picker ever changes.
     *
     * Phase 5 finding H-4: classification into standardized vs. custom
     * used to trust the client-supplied `custom` boolean, which
     * ConsultationController::store never validates (symptoms_payload is
     * only checked as `required|string`). A patient could submit
     * {"name": "<arbitrary text>", "custom": false} and force free text
     * into the standardized bucket, which carries no privacy suppression
     * (only the custom bucket is gated at CUSTOM_TERM_MIN_REPORTS).
     * Membership in this vocabulary is now the sole classification
     * authority — the `custom` flag is not read at all — so an
     * unrecognized name can never bypass the k=3 suppression regardless
     * of what the client claims.
     */
    private const STANDARDIZED_SYMPTOMS = [
        'Headache',
        'Fever',
        'Cough',
        'Sore Throat',
        'Body Pain',
        'Fatigue',
        'Nausea / Vomiting',
        'Diarrhea',
        'Runny Nose',
        'Shortness of Breath',
        'Loss of Appetite',
        'Abdominal Pain',
    ];

    /**
     * @param  iterable<mixed>  $symptomsDescRows  Each element is one request's already
     *                                             array-cast `symptoms_desc` value (or
     *                                             null/malformed — handled defensively).
     */
    public function summarize(iterable $symptomsDescRows): array
    {
        $validRequests = 0;
        $malformedRequests = 0;

        /** @var array<string, array{label: string, count: int}> $standardized */
        $standardized = [];
        /** @var array<string, array{label: string, count: int}> $customTerms */
        $customTerms = [];
        $requestsWithCustom = 0;

        $severityCounts = array_fill_keys(self::VALID_SEVERITIES, 0);
        $severityValidEntries = 0;

        $normalizedVocabulary = array_map(
            fn (string $s) => $this->normalize($s),
            self::STANDARDIZED_SYMPTOMS,
        );

        foreach ($symptomsDescRows as $row) {
            if (! is_array($row) || $row === []) {
                $malformedRequests++;

                continue;
            }

            $rowStandardizedSeen = [];
            $rowCustomSeen = [];
            $rowHasValidEntry = false;

            foreach ($row as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                $rowHasValidEntry = true;
                $normalized = $this->normalize($name);

                // The client-supplied `custom` flag is never read — see
                // the STANDARDIZED_SYMPTOMS docblock. Classification is
                // name membership only, in both directions: a recognized
                // name is always standardized even if the client falsely
                // flags it custom, and an unrecognized name is always
                // custom even if the client falsely flags it not-custom.
                $isCustom = ! in_array($normalized, $normalizedVocabulary, true);

                // First-seen casing wins as the display label for a normalized
                // name, so "headache" appearing twice in one request doesn't
                // overwrite the first entry's casing.
                if ($isCustom) {
                    $rowCustomSeen[$normalized] ??= trim($name);
                } else {
                    $rowStandardizedSeen[$normalized] ??= trim($name);
                }

                $severity = $entry['severity'] ?? null;
                if (is_numeric($severity) && in_array((int) $severity, self::VALID_SEVERITIES, true)) {
                    $severityCounts[(int) $severity]++;
                    $severityValidEntries++;
                }
            }

            if (! $rowHasValidEntry) {
                $malformedRequests++;

                continue;
            }

            $validRequests++;

            foreach ($rowStandardizedSeen as $key => $label) {
                $standardized[$key] ??= ['label' => $label, 'count' => 0];
                $standardized[$key]['count']++;
            }

            if ($rowCustomSeen !== []) {
                $requestsWithCustom++;
            }

            foreach ($rowCustomSeen as $key => $label) {
                $customTerms[$key] ??= ['label' => $label, 'count' => 0];
                $customTerms[$key]['count']++;
            }
        }

        $qualifyingCustomTerms = array_filter(
            $customTerms,
            fn (array $term) => $term['count'] >= self::CUSTOM_TERM_MIN_REPORTS,
        );
        $suppressedTermsCount = count($customTerms) - count($qualifyingCustomTerms);

        return [
            'valid_requests' => $validRequests,
            'malformed_requests' => $malformedRequests,
            'standardized' => $this->toSortedPairs($standardized),
            'custom' => [
                'requests_with_custom' => $requestsWithCustom,
                'percentage' => $validRequests > 0
                    ? round(($requestsWithCustom / $validRequests) * 100, 1)
                    : null,
                'terms' => $this->toSortedPairs($qualifyingCustomTerms),
                'suppressed_terms_count' => $suppressedTermsCount,
            ],
            'severity' => [
                'counts' => $severityCounts,
                'valid_entries' => $severityValidEntries,
                'severe_count' => $severityCounts[4],
                'default_bucket' => self::DEFAULT_SEVERITY_BUCKET,
            ],
        ];
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    /**
     * @param  array<string, array{label: string, count: int}>  $entries
     * @return list<array{name: string, count: int}>
     */
    private function toSortedPairs(array $entries): array
    {
        $pairs = array_map(
            fn (array $entry) => ['name' => $entry['label'], 'count' => $entry['count']],
            array_values($entries),
        );

        usort($pairs, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $pairs;
    }
}
