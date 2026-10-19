// Check — nginx must not keep the address of a php container that no longer
// exists.
//
//   docker/nginx/default.conf   fastcgi_pass $php_upstream
//                                resolver 127.0.0.11 valid=10s ipv6=off
//
// The php container is recreated by every build and comes back with a different
// address. nginx resolves an upstream NAME once, when it reads the configuration,
// and keeps that address until it is reloaded: with a plain `fastcgi_pass
// php:9000` the server goes on talking to the container that no longer exists
// and answers 502. The build succeeds, the site is dead, and nothing in the
// build output says why.
//
// The fix is to resolve the name per request, through Docker's embedded DNS, and
// let the answer be cached for a few seconds. A container that comes back with a
// new address is then picked up on its own, with no reload and no restart.
//
// The check is on the CONFIG, because that is where the bug lives: the symptom
// only shows up when a build and a request happen to overlap in time.
//
// Usage:  node tests/js/check_nginx_upstream.mjs

import { readFileSync } from 'node:fs';

const conf = readFileSync(new URL('../../docker/nginx/default.conf', import.meta.url), 'utf8');

// The comments explain the why; they are stripped so a sentence in a comment
// cannot satisfy a check that is about the directives.
const directives = conf
    .split('\n')
    .filter((line) => !line.trim().startsWith('#'))
    .join('\n');

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

console.log('--- the upstream is resolved per request, not at startup ---');
{
    // Docker's embedded DNS. It exists on the loopback of every container
    // attached to a user-defined network, which is what Compose creates.
    check('a resolver is declared', /^\s*resolver\s+[^;]+;/m.test(directives),
        (/^\s*resolver\s+([^;]+);/m.exec(directives) ?? [])[1] ?? '');
    check('and it is Docker\'s', /resolver\s+127\.0\.0\.11\b/.test(directives));
    check('the answer is not cached forever', /resolver\s+[^;]*valid=\d+s/.test(directives),
        (/resolver\s+([^;]+);/m.exec(directives) ?? [])[1] ?? '');
    check('the resolver is not asked for AAAA', /resolver\s+[^;]*ipv6=off/.test(directives));
}

console.log('\n--- every fastcgi_pass goes through the variable ---');
{
    const passes = [...directives.matchAll(/fastcgi_pass\s+([^;]+);/g)].map((m) => m[1].trim());
    check('there is at least one fastcgi_pass', passes.length > 0, `${passes.length}`);
    for (const target of passes) {
        check(`fastcgi_pass ${target} is a variable`, target.startsWith('$'), target);
    }
    check('no fastcgi_pass names the container directly',
        !passes.some((t) => /(^|\s)php(:|\s|$)/.test(t)), passes.join(' | '));
}

console.log('\n--- the variable points at the php container ---');
{
    const set = /set\s+(\$php_upstream)\s+([^;]+);/.exec(directives);
    check('the variable is set', set !== null, set ? set[0] : '');
    if (set) {
        check('it points at the php service', /^php:\d+$/.test(set[2].trim()), set[2]);
        check('and the fastcgi_pass uses that same variable',
            [...directives.matchAll(/fastcgi_pass\s+([^;]+);/g)]
                .every((m) => m[1].trim() === set[1]),
            'fastcgi_pass uses ' + set[1]);
    }
}

console.log('\n--- no upstream block that would keep the address ---');
{
    // An `upstream` block with a plain `server php:9000;` has the same defect:
    // the name is resolved when the block is read, and the address is kept.
    const hasUpstream = /^\s*upstream\s+\w+\s*\{/m.test(directives);
    check('there is no upstream block with a literal server', !hasUpstream,
        hasUpstream ? 'an upstream block would keep the address' : '');
}

console.log(bad === 0
    ? '\nOK: nginx follows the php container, a recreated one does not break the site'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);
