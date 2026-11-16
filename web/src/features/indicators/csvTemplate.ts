/** Columns of the Aula+ template; the CSV upload reads the same ones. */
export const TEMPLATE_HEADER = ["id_alumno", "alumno", "grupo", "valor"]

/**
 * The Aula+ template as Excel in Spanish (Uruguay) opens it: `;` as the list
 * separator (the comma is the decimal one), decimals with a comma, CRLF line
 * ends and a UTF-8 BOM so accents read right.
 */
export function buildTemplateCsv(
  rows: { student_id: number; name: string; group_name: string; value: number | null }[],
): string {
  const lines = [
    TEMPLATE_HEADER.join(";"),
    ...rows.map((row) =>
      [row.student_id, csvCell(row.name), csvCell(row.group_name), row.value === null ? "" : String(row.value).replace(".", ",")].join(
        ";",
      ),
    ),
  ]
  return "\uFEFF" + lines.join("\r\n")
}

function csvCell(value: string): string {
  return /[",;\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/**
 * Reads the Aula+ template saved as CSV (comma, semicolon or tab): the
 * «id_alumno» and «valor» columns. Rows without a student id or with a value
 * that is not a number are counted as bad; rows with an empty value are left
 * alone.
 */
export function parseTemplateCsv(text: string): { values: { student_id: number; value: number }[]; bad: number } {
  const lines = text
    .replace(/^\uFEFF/, "")
    .split(/\r?\n/)
    .filter((line) => line.trim() !== "")
  if (lines.length === 0) return { values: [], bad: 0 }
  const separator = lines[0].includes(";") ? ";" : lines[0].includes("\t") ? "\t" : ","
  const header = splitCsvLine(lines[0], separator).map((cell) => cell.trim().toLowerCase())
  const idColumn = header.indexOf("id_alumno")
  const valueColumn = header.indexOf("valor")
  if (idColumn === -1 || valueColumn === -1) return { values: [], bad: lines.length - 1 }

  const values: { student_id: number; value: number }[] = []
  let bad = 0
  for (const line of lines.slice(1)) {
    const cells = splitCsvLine(line, separator)
    const rawValue = (cells[valueColumn] ?? "").trim()
    if (rawValue === "") continue
    const studentId = Number((cells[idColumn] ?? "").trim())
    const value = Number(rawValue.replace(",", "."))
    if (!Number.isInteger(studentId) || studentId <= 0 || Number.isNaN(value)) {
      bad++
      continue
    }
    values.push({ student_id: studentId, value })
  }
  return { values, bad }
}

function splitCsvLine(line: string, separator: string): string[] {
  const cells: string[] = []
  let current = ""
  let quoted = false
  for (let index = 0; index < line.length; index++) {
    const char = line[index]
    if (char === '"') {
      if (quoted && line[index + 1] === '"') {
        current += '"'
        index++
      } else {
        quoted = !quoted
      }
    } else if (char === separator && !quoted) {
      cells.push(current)
      current = ""
    } else {
      current += char
    }
  }
  cells.push(current)
  return cells
}
