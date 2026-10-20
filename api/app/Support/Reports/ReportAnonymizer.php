<?php

namespace App\Support\Reports;

use App\Models\School;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Automatic first pass at removing identifying data from a report's text
 * before psychopedagogy reviews it (ClickUp 86e3jujgx, CLAUDE.md rule 11).
 *
 * It only covers what it can recognise: the student's name (every part, any
 * case, with or without accents), birth date, school name, e-mails, phone
 * numbers, identity-document numbers, any other full date, and the value of
 * labelled lines such as «Nombre:», «Domicilio:» or «Madre:». It is NOT a
 * guarantee — that is why nothing is sent to the AI until a person reviews
 * and edits the result and confirms the send. Names of other people in running
 * text (relatives, previous teachers) can only be caught by that review.
 */
class ReportAnonymizer
{
    protected const MIN_PART_LENGTH = 3;

    /** Name fragments too generic to treat as a name (and likely common words). */
    protected const STOPWORDS = ['del', 'las', 'los', 'van', 'von', 'dos', 'san', 'que', 'con', 'por', 'para'];

    /** Labels whose line value identifies someone. */
    protected const LABELS = [
        'nombre', 'nombres', 'apellido', 'apellidos', 'alumno', 'alumna', 'estudiante', 'paciente',
        'cedula', 'c\.i', 'ci', 'documento', 'domicilio', 'direccion', 'dirección', 'telefono', 'tel', 'celular',
        'madre', 'padre', 'tutor', 'tutora', 'responsable', 'familiar', 'referente', 'mail', 'correo', 'e-mail', 'email',
        'fecha de nacimiento', 'f\.\s*nac', 'nacimiento', 'localidad', 'barrio',
    ];

    /**
     * @param  list<string>  $terms  names/words to replace wherever they appear
     */
    public function __construct(protected array $terms) {}

    public static function forStudent(Student $student, School $school): self
    {
        $terms = [];

        foreach (self::words((string) $student->full_name) as $word) {
            $terms[] = $word;
        }

        // The school's own name, as a whole phrase (it may be the only thing
        // that says where the student studies).
        if (filled($school->name)) {
            $terms[] = (string) $school->name;
        }

        return new self(array_values(array_unique($terms)));
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    public function anonymizePages(array $pages): array
    {
        return array_map(fn (string $page): string => $this->anonymize($page), $pages);
    }

    public function anonymize(string $text): string
    {
        $labels = implode('|', self::LABELS);
        // «Madre: Ana Pérez» → «Madre: [DATO]» (to the end of the line).
        $text = (string) preg_replace_callback(
            '/^([ \t]*(?:'.$labels.')[ \t]*[:\-–][ \t]*)(\S[^\n]*)$/imu',
            fn (array $m): string => $m[1].'[DATO REDACTADO]',
            $text,
        );

        $text = (string) preg_replace('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/u', '[EMAIL]', $text);
        // Identity document: 1.234.567-8 / 12345678 near «CI»/«cédula» is caught by the label rule;
        // the bare Uruguayan format with separators is caught here.
        $text = (string) preg_replace('/\b\d{1,2}\.?\d{3}\.?\d{3}-\d\b/u', '[DOCUMENTO]', $text);
        // Dates (dd/mm/yyyy, dd-mm-yy, d.m.yyyy) and «12 de marzo de 2015».
        $text = (string) preg_replace('/\b\d{1,2}\s*[\/\-.]\s*\d{1,2}\s*[\/\-.]\s*\d{2,4}\b/u', '[FECHA]', $text);
        $text = (string) preg_replace('/\b\d{1,2}\s+de\s+\p{L}+\s+de(?:l)?\s+\d{4}\b/iu', '[FECHA]', $text);
        // Phone numbers: 8+ digits, optionally grouped or with a +598 prefix.
        $text = (string) preg_replace('/(?<![\d])(?:\+?\d{1,3}[\s.\-]?)?(?:\d[\s.\-]?){7,11}\d(?![\d])/u', '[TELÉFONO]', $text);

        foreach ($this->terms as $term) {
            $text = $this->replaceTerm($text, $term);
        }

        return $text;
    }

    /**
     * Whether any of the student's name parts or the school name is still in
     * the text — the check before a send: a person's edit must not bring a name back.
     *
     * @param  list<string>  $pages
     */
    public function leaksIdentity(array $pages): bool
    {
        foreach ($pages as $page) {
            foreach ($this->terms as $term) {
                if ($this->replaceTerm($page, $term) !== $page) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replaces every occurrence of a term, ignoring case and accents.
     */
    protected function replaceTerm(string $text, string $term): string
    {
        $pattern = $this->accentInsensitivePattern($term);

        return (string) preg_replace('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', '[ALUMNO]', $text);
    }

    /**
     * A regex that matches the term with any accent variant of each vowel/ñ.
     */
    protected function accentInsensitivePattern(string $term): string
    {
        $map = [
            'a' => '[aáàäâ]', 'e' => '[eéèëê]', 'i' => '[iíìïî]', 'o' => '[oóòöô]', 'u' => '[uúùüû]', 'n' => '[nñ]',
        ];
        $out = '';

        foreach (mb_str_split(Str::lower($term)) as $char) {
            $base = Str::ascii($char);
            $out .= isset($map[$base]) && mb_strlen($char) === 1 && ctype_alpha($base)
                ? $map[$base]
                : preg_quote($char, '/');
        }

        return $out;
    }

    /**
     * Name parts long enough to be a name, without generic particles.
     *
     * @return list<string>
     */
    protected static function words(string $name): array
    {
        $parts = preg_split('/[^\p{L}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $part): bool => mb_strlen($part) >= self::MIN_PART_LENGTH
                && ! in_array(Str::lower($part), self::STOPWORDS, true),
        ));
    }
}
