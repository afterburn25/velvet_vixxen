'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = {window: {}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/api-client.js'), 'utf8'), context);
const read = context.window.VELVET_API.read;
const response = (status, body) => ({status, ok: status >= 200 && status < 300, text: async () => body});

(async () => {
  let checks = 0;
  const asset = await read(response(200, '{"ok":true,"asset":{"id":"synthetic-upload"}}'));
  assert.equal(asset.asset.id, 'synthetic-upload'); checks++;
  const cases = [
    [404, 'File not found. ', /service could not be found/],
    [200, 'File not found. ', /service could not be found/],
    [404, '<h1>Not found</h1>', /service could not be found/],
    [413, '<h1>Payload Too Large</h1>', /server’s upload limit/],
    [401, '<h1>Sign in</h1>', /session has expired/],
    [419, '', /session has expired/],
    [403, '<h1>Forbidden</h1>', /server refused/],
    [500, '<pre>private server detail</pre>', /server could not complete/],
    [200, '<script>alert("host error")</script>', /unexpected response/],
    [200, 'null', /unexpected response/],
    [200, '[]', /unexpected response/],
    [422, '{"ok":false,"message":"Choose a JPG, PNG, or WebP image."}', /Choose a JPG, PNG, or WebP image/],
    [200, '{"ok":false,"message":"Upload rejected."}', /Upload rejected/],
    [500, '{"ok":true}', /The request could not be completed/],
  ];
  for (const [status, body, expected] of cases) {
    await assert.rejects(read(response(status, body)), error => {
      assert.match(error.message, expected);
      assert.doesNotMatch(error.message, /Unexpected token|not valid JSON|private server detail|<script>|<h1>/);
      return true;
    });
    checks++;
  }
  console.log(`API response handling passed (${checks} checks; plain missing-file errors, host HTML, upload limits, session errors and valid JSON results).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
