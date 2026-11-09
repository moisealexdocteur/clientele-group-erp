// Sert la PWA compilée et relaie /api vers l'API Laravel, comme Traefik en préproduction.
import { createReadStream, existsSync, statSync } from 'node:fs'
import { createServer } from 'node:http'
import { extname, join, normalize } from 'node:path'

const root = new URL('../apps/web/dist/', import.meta.url).pathname
const api = process.env.E2E_API ?? 'http://127.0.0.1:8001'
const port = Number(process.env.E2E_PORT ?? 4180)
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.webmanifest': 'application/manifest+json', '.json': 'application/json', '.woff2': 'font/woff2' }

createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost')
  if (url.pathname.startsWith('/api/')) {
    const chunks = []
    for await (const chunk of req) chunks.push(chunk)
    const headers = { ...req.headers }
    delete headers.host
    const upstream = await fetch(api + url.pathname + url.search, { method: req.method, headers, body: chunks.length ? Buffer.concat(chunks) : undefined, redirect: 'manual' })
    res.writeHead(upstream.status, Object.fromEntries([...upstream.headers].filter(([key]) => !['content-encoding', 'transfer-encoding', 'content-length'].includes(key))))
    res.end(Buffer.from(await upstream.arrayBuffer()))
    return
  }
  let file = normalize(join(root, decodeURIComponent(url.pathname)))
  if (!file.startsWith(root) || !existsSync(file) || statSync(file).isDirectory()) file = join(root, 'index.html')
  res.writeHead(200, { 'content-type': types[extname(file)] ?? 'application/octet-stream' })
  createReadStream(file).pipe(res)
}).listen(port, '127.0.0.1')
