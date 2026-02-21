/**
 * IPFS Blockchain Ingestion Service (BIS)
 *
 * A real BIS server that uploads document metadata to an IPFS node.
 * Replaces the mock server for production use.
 *
 * IPFS Node: 10.211.171.140
 *   - API:     http://10.211.171.140:5001/api/v0/
 *   - Gateway: http://10.211.171.140:8080/ipfs/<CID>
 *
 * Start: node ipfs_bis_server.js
 * Listens on: http://localhost:4000/ingest
 */

const http = require('http');
const crypto = require('crypto');

// ─── Configuration ──────────────────────────────────────────────
const PORT = 4000;
const IPFS_API_HOST = '10.211.171.140';
const IPFS_API_PORT = 5001;
const IPFS_GATEWAY = `http://${IPFS_API_HOST}:8080/ipfs`;

// ─── Helpers ────────────────────────────────────────────────────

/**
 * Upload content to IPFS via the /api/v0/add endpoint.
 * Uses multipart/form-data since IPFS API requires it.
 * Returns a Promise that resolves to { Name, Hash, Size }.
 */
function ipfsAdd(content, filename) {
    return new Promise((resolve, reject) => {
        const boundary = '----IPFSBoundary' + crypto.randomBytes(8).toString('hex');

        // Build multipart body
        const bodyParts = [
            `--${boundary}\r\n`,
            `Content-Disposition: form-data; name="file"; filename="${filename}"\r\n`,
            `Content-Type: application/json\r\n`,
            `\r\n`,
            content,
            `\r\n`,
            `--${boundary}--\r\n`,
        ];
        const bodyBuffer = Buffer.from(bodyParts.join(''));

        const options = {
            hostname: IPFS_API_HOST,
            port: IPFS_API_PORT,
            path: '/api/v0/add?pin=true&wrap-with-directory=false',
            method: 'POST',
            headers: {
                'Content-Type': `multipart/form-data; boundary=${boundary}`,
                'Content-Length': bodyBuffer.length,
            },
            timeout: 15000,
        };

        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => {
                try {
                    const parsed = JSON.parse(data);
                    if (parsed.Hash) {
                        resolve(parsed);
                    } else {
                        reject(new Error('IPFS add did not return a Hash: ' + data));
                    }
                } catch (e) {
                    reject(new Error('Failed to parse IPFS response: ' + data));
                }
            });
        });

        req.on('error', (err) => reject(new Error('IPFS connection error: ' + err.message)));
        req.on('timeout', () => { req.destroy(); reject(new Error('IPFS request timed out')); });
        req.write(bodyBuffer);
        req.end();
    });
}

/**
 * Write a file to the IPFS Mutable File System (MFS)
 * so it appears in the IPFS Web UI under Files.
 */
function ipfsMfsWrite(content, mfsPath) {
    return new Promise((resolve, reject) => {
        const boundary = '----IPFSBoundary' + crypto.randomBytes(8).toString('hex');

        const bodyParts = [
            `--${boundary}\r\n`,
            `Content-Disposition: form-data; name="file"; filename="data.json"\r\n`,
            `Content-Type: application/json\r\n`,
            `\r\n`,
            content,
            `\r\n`,
            `--${boundary}--\r\n`,
        ];
        const bodyBuffer = Buffer.from(bodyParts.join(''));

        const encodedPath = encodeURIComponent(mfsPath);
        const options = {
            hostname: IPFS_API_HOST,
            port: IPFS_API_PORT,
            path: `/api/v0/files/write?arg=${encodedPath}&create=true&parents=true&truncate=true`,
            method: 'POST',
            headers: {
                'Content-Type': `multipart/form-data; boundary=${boundary}`,
                'Content-Length': bodyBuffer.length,
            },
            timeout: 15000,
        };

        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => resolve(data));
        });

        req.on('error', (err) => reject(new Error('MFS write error: ' + err.message)));
        req.on('timeout', () => { req.destroy(); reject(new Error('MFS write timed out')); });
        req.write(bodyBuffer);
        req.end();
    });
}

/**
 * Get IPFS node identity to verify connectivity.
 */
function ipfsId() {
    return new Promise((resolve, reject) => {
        const options = {
            hostname: IPFS_API_HOST,
            port: IPFS_API_PORT,
            path: '/api/v0/id',
            method: 'POST',
            timeout: 5000,
        };

        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => {
                try { resolve(JSON.parse(data)); }
                catch (e) { reject(new Error('Failed to parse IPFS ID response')); }
            });
        });

        req.on('error', (err) => reject(new Error('Cannot reach IPFS node: ' + err.message)));
        req.on('timeout', () => { req.destroy(); reject(new Error('IPFS node unreachable (timeout)')); });
        req.end();
    });
}

// ─── Main Server ────────────────────────────────────────────────

const server = http.createServer(async (req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Source');

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    // ─── Ingest Endpoint ────────────────────────────────────
    if (req.method === 'POST' && req.url === '/ingest') {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', async () => {
            try {
                const payload = JSON.parse(body);

                console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                console.log('📥 Received document ingestion request:');
                console.log(`   Document ID : ${payload.document_id}`);
                console.log(`   Patient UUID: ${payload.patient_uuid || 'N/A'}`);
                console.log(`   MIME Type   : ${payload.mime_type}`);
                console.log(`   Category    : ${payload.category || 'N/A'}`);
                console.log(`   Timestamp   : ${payload.timestamp}`);
                console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

                // Compute SHA-256 hash of the metadata
                const recordHash = 'sha256:' + crypto
                    .createHash('sha256')
                    .update(JSON.stringify(payload))
                    .digest('hex');

                // Create the document record to store in IPFS
                const ipfsRecord = {
                    ...payload,
                    record_hash: recordHash,
                    ingested_at: new Date().toISOString(),
                    source: 'OpenEMR-BIM',
                };

                const jsonContent = JSON.stringify(ipfsRecord, null, 2);
                const filename = `openemr_doc_${payload.document_id}_${Date.now()}.json`;

                // Step 1: Upload to IPFS (auto-pinned)
                console.log('📤 Uploading to IPFS...');
                const ipfsResult = await ipfsAdd(jsonContent, filename);
                const cid = ipfsResult.Hash;
                console.log(`✅ IPFS CID: ${cid}`);
                console.log(`   Gateway : ${IPFS_GATEWAY}/${cid}`);
                console.log(`   Size    : ${ipfsResult.Size} bytes`);

                // Step 2: Write to MFS (visible in IPFS Web UI)
                const mfsPath = `/openemr/documents/${filename}`;
                try {
                    await ipfsMfsWrite(jsonContent, mfsPath);
                    console.log(`📁 MFS Path: ${mfsPath}`);
                } catch (mfsErr) {
                    console.warn(`⚠️  MFS write warning: ${mfsErr.message} (CID still valid)`);
                }

                console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');

                // Return the CID as the blockchain_tx to OpenEMR
                const response = {
                    status: 'anchored',
                    blockchain_tx: cid,
                    record_hash: recordHash,
                    ipfs_gateway_url: `${IPFS_GATEWAY}/${cid}`,
                    mfs_path: mfsPath,
                    chain: 'ipfs',
                    timestamp: new Date().toISOString(),
                };

                res.writeHead(200);
                res.end(JSON.stringify(response));

            } catch (e) {
                console.error('❌ Error:', e.message);
                res.writeHead(500);
                res.end(JSON.stringify({ error: e.message }));
            }
        });

        // ─── Health Endpoint ────────────────────────────────────
    } else if (req.url === '/health') {
        try {
            const nodeInfo = await ipfsId();
            res.writeHead(200);
            res.end(JSON.stringify({
                status: 'ok',
                service: 'ipfs-bis',
                ipfs_node: {
                    peer_id: nodeInfo.ID,
                    agent: nodeInfo.AgentVersion,
                    api: `http://${IPFS_API_HOST}:${IPFS_API_PORT}`,
                    gateway: IPFS_GATEWAY,
                }
            }));
        } catch (err) {
            res.writeHead(503);
            res.end(JSON.stringify({
                status: 'error',
                service: 'ipfs-bis',
                error: err.message,
            }));
        }

        // ─── 404 ────────────────────────────────────────────────
    } else {
        res.writeHead(404);
        res.end(JSON.stringify({ error: 'Not found' }));
    }
});

// ─── Startup ────────────────────────────────────────────────────
server.listen(PORT, async () => {
    console.log(`\n🔗 IPFS BIS Server running at http://localhost:${PORT}`);
    console.log(`   POST http://localhost:${PORT}/ingest  — Ingest endpoint`);
    console.log(`   GET  http://localhost:${PORT}/health   — Health check\n`);

    // Verify IPFS connectivity on startup
    try {
        const nodeInfo = await ipfsId();
        console.log(`✅ Connected to IPFS node!`);
        console.log(`   Peer ID : ${nodeInfo.ID}`);
        console.log(`   Agent   : ${nodeInfo.AgentVersion}`);
        console.log(`   API     : http://${IPFS_API_HOST}:${IPFS_API_PORT}`);
        console.log(`   Gateway : ${IPFS_GATEWAY}\n`);
    } catch (err) {
        console.error(`⚠️  Warning: Cannot reach IPFS node at ${IPFS_API_HOST}:${IPFS_API_PORT}`);
        console.error(`   Error: ${err.message}`);
        console.error(`   Server will still start — IPFS uploads will fail until node is reachable.\n`);
    }

    console.log('Waiting for ingestion requests...\n');
});
