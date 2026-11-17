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

/** One row of an uploaded CSV: who it is, as the file says it, and the value. */
export interface TemplateRow {
  /** The Aula+ code («id_alumno»), when the file has it. */
  student_id: number | null
  name: string | null
  group: string | null
  value: number
}

/**
 * Reads the Aula+ template saved as CSV (comma, semicolon or tab), or any
 * sheet with the same column names: «valor» and who the row is — the
 * «id_alumno» code or, when the file has no code, the «alumno» name (and
 * «grupo», when there is one). Who a name is gets resolved by the server,
 * which asks when a name is repeated (PO answer of 17/11). Rows with a code
 * that is not a number, without a code or a name, or with a value that is
 * not a number are counted as bad; rows with an empty value are left alone.
 */
export function parseTemplateCsv(text: string): { rows: TemplateRow[]; bad: number } {
  const lines = text
    .replace(/^\uFEFF/, "")
    .split(/\r?\n/)
    .filter((line) => line.trim() !== "")
  if (lines.length === 0) return { rows: [], bad: 0 }
  const separator = lines[0].includes(";") ? ";" : lines[0].includes("\t") ? "\t" : ","
  const header = splitCsvLine(lines[0], separator).map((cell) => cell.trim().toLowerCase())
  const idColumn = header.indexOf("id_alumno")
  const nameColumn = header.indexOf("alumno")
  const groupColumn = header.indexOf("grupo")
  const valueColumn = header.indexOf("valor")
  if (valueColumn === -1 || (idColumn === -1 && nameColumn === -1)) return { rows: [], bad: lines.length - 1 }

  const rows: TemplateRow[] = []
  let bad = 0
  for (const line of lines.slice(1)) {
    const cells = splitCsvLine(line, separator)
    const cell = (column: number) => (column === -1 ? "" : (cells[column] ?? "").trim())
    const rawValue = cell(valueColumn)
    if (rawValue === "") continue
    const rawId = cell(idColumn)
    const studentId = rawId === "" ? null : Number(rawId)
    const name = cell(nameColumn)
    const value = Number(rawValue.replace(",", "."))
    const badId = studentId !== null && (!Number.isInteger(studentId) || studentId <= 0)
    if (badId || (studentId === null && name === "") || Number.isNaN(value)) {
      bad++
      continue
    }
    rows.push({ student_id: studentId, name: name || null, group: cell(groupColumn) || null, value })
  }
  return { rows, bad }
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
