/**
 * Mock Blockchain Ingestion Service (BIS)
 *
 * A minimal Express.js server that simulates the external blockchain
 * anchoring microservice. Use this for local development and testing.
 *
 * Start: node mock_bis_server.js
 * Listens on: http://localhost:4000/ingest
 */

const http = require('http');

const PORT = 4000;

const server = http.createServer((req, res) => {
    // CORS headers for flexibility
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Source');

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    if (req.method === 'POST' && req.url === '/ingest') {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', () => {
            try {
                const payload = JSON.parse(body);
                console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                console.log('📥 Received document ingestion request:');
                console.log(`   Document ID : ${payload.document_id}`);
                console.log(`   Patient UUID: ${payload.patient_uuid || 'N/A'}`);
                console.log(`   MIME Type   : ${payload.mime_type}`);
                console.log(`   Category    : ${payload.category}`);
                console.log(`   Timestamp   : ${payload.timestamp}`);
                console.log(`   File Hash   : ${payload.file_hash || 'N/A'}`);
                console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

                // Simulate blockchain anchoring
                const txHash = '0x' + [...Array(64)].map(() =>
                    Math.floor(Math.random() * 16).toString(16)
                ).join('');

                const recordHash = 'sha256:' + [...Array(64)].map(() =>
                    Math.floor(Math.random() * 16).toString(16)
                ).join('');

                const response = {
                    status: 'anchored',
                    blockchain_tx: txHash,
                    record_hash: recordHash,
                    block_number: Math.floor(Math.random() * 1000000) + 18000000,
                    chain: 'ethereum-sepolia',
                    timestamp: new Date().toISOString(),
                };

                console.log('✅ Anchored! TX:', txHash.substring(0, 20) + '...');

                res.writeHead(200);
                res.end(JSON.stringify(response));
            } catch (e) {
                console.error('❌ Error parsing request:', e.message);
                res.writeHead(400);
                res.end(JSON.stringify({ error: 'Invalid JSON', message: e.message }));
            }
        });
    } else {
        // Health check or unknown routes
        if (req.url === '/health') {
            res.writeHead(200);
            res.end(JSON.stringify({ status: 'ok', service: 'mock-bis' }));
        } else {
            res.writeHead(404);
            res.end(JSON.stringify({ error: 'Not found' }));
        }
    }
});

server.listen(PORT, () => {
    console.log(`\n🔗 Mock BIS Server running at http://localhost:${PORT}`);
    console.log(`   POST http://localhost:${PORT}/ingest  — Ingest endpoint`);
    console.log(`   GET  http://localhost:${PORT}/health   — Health check`);
    console.log('\nWaiting for ingestion requests...\n');
});
