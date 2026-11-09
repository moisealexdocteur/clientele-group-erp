import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'

/* Propriétaire de recette et jeton, partagés par les tests. */
export default function globalSetup(): void {
  mkdirSync('output', { recursive: true })
  const token = execFileSync('php', ['seed.php'], { encoding: 'utf8' }).trim()
  writeFileSync('output/.token', token)
}
