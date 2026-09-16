// Real QR decoding, entirely in memory; never loads Laravel, a database or an account.
import {chromium} from '@playwright/test';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';

const require = createRequire(import.meta.url);
const php = String.raw`
require 'vendor/autoload.php';
$google = new PragmaRX\Google2FA\Google2FA;
$writer = new BaconQrCode\Writer(new BaconQrCode\Renderer\ImageRenderer(
    new BaconQrCode\Renderer\RendererStyle\RendererStyle(232, 4),
    new BaconQrCode\Renderer\Image\SvgImageBackEnd
));
$holders = ['demo@example.test', 'synthetic+alias@example.test', str_repeat('a',64).'@'.str_repeat('b',63).'.'.str_repeat('c',63).'.'.str_repeat('d',56).'.test'];
$vectors = [];
foreach ($holders as $holder) {
    $uri = $google->getQRCodeUrl('BRN Mail', $holder, $google->generateSecretKey(32));
    $vectors[] = ['svg' => preg_replace('/^<\?xml[^>]+>\s*/', '', $writer->writeString($uri)), 'digest' => hash('sha256', $uri)];
}
echo json_encode($vectors, JSON_THROW_ON_ERROR);
`;
let browser;
try {
    const generated = spawnSync('php', ['-r', php], {encoding: 'utf8', maxBuffer: 1024 * 1024});
    if (generated.status !== 0) throw new Error('Synthetic QR generation failed');
    const vectors = JSON.parse(generated.stdout);
    const decoderPath = process.env.BRNMAIL_QR_DECODER || require.resolve('jsqr');
    const decoder = readFileSync(decoderPath, 'utf8');
    browser = await chromium.launch({headless: true, channel: process.env.BRNMAIL_BROWSER_CHANNEL || 'chrome'});
    const page = await browser.newPage();
    let network = 0;
    await page.route('**/*', route => {network++; return route.abort();});
    await page.setContent('<!doctype html><html><head><title>In-memory synthetic QR QA</title></head><body></body></html>');
    await page.addScriptTag({content: decoder});
    let checks = 0;
    for (const vector of vectors) for (const size of [232, 464]) {
        const decoded = await page.evaluate(async ({svg, size}) => {
            const image = new Image();
            const url = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
            try {
                image.src = url;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = size; canvas.height = size;
                const ctx = canvas.getContext('2d', {willReadFrequently: true});
                ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, size, size);
                ctx.drawImage(image, 0, 0, size, size);
                const pixels = ctx.getImageData(0, 0, size, size);
                return window.jsQR(pixels.data, size, size, {inversionAttempts: 'dontInvert'})?.data ?? null;
            } finally {URL.revokeObjectURL(url);}
        }, {svg: vector.svg, size});
        if (!decoded || createHash('sha256').update(decoded).digest('hex') !== vector.digest) {
            throw new Error('Synthetic QR decoding did not match the generated payload');
        }
        checks++;
    }
    if (network !== 0) throw new Error('Unexpected network request');
    console.log(JSON.stringify({qr_decode_checks: checks, payloads_match: true, network_calls: network, secrets_written: false, database_access: false}));
} catch {
    console.error(JSON.stringify({qr_decode_passed: false, details: 'Synthetic QR generation/decoding gate failed; no sensitive payload is logged.'}));
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
}
