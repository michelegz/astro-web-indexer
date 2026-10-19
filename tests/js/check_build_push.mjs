// Check — build.sh must build the images the push names, and must not push
// without a credential.
//
//   build.sh   compose_base build            -> ghcr.io/<owner>/...:<version>
//              docker push ghcr.io/<owner>/...:<version>
//
// Two things that broke, and that the file has to keep not breaking:
//
// 1. The dev compose layer used to be merged into the build, and it sets
//    `image: nginx:1.27`: the build tagged nginx:1.27, the
//    ghcr.io/.../astro-web-indexer-nginx:<version> tag was never produced,
//    and `push` (which only names images and never touches Compose) shipped
//    the PREVIOUS build's nginx without saying a word. The build must go
//    through compose_base, the base file alone.
//
// 2. A push without a credential is refused by the registry only after the
//    layers are prepared, so the output was twenty lines of progress and
//    one "unauthorized", with nothing pointing at the missing login. The
//    push has to check the credential before the first push.
//
// Usage:  node tests/js/check_build_push.mjs

import { readFileSync } from 'node:fs';

const sh = readFileSync(new URL('../../build.sh', import.meta.url), 'utf8');

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

// The part of the script that matters: the build case and the push case.
const buildCase = sh.slice(sh.indexOf('\n    build)'), sh.indexOf('\n    start)'));
const pushCase = sh.slice(sh.indexOf('\n    push)'), sh.indexOf('\n    help|'));

console.log('--- the build produces the image the push names ---');
{
    check('the build goes through compose_base', /compose_base\s+build/.test(buildCase),
        'the dev layer must not decide the tag of the release image');
    check('  and not through a bare `docker compose build`',
        !/^\s*docker compose build/m.test(buildCase));
    check('the lifecycle commands go through compose_live',
        /compose_live\s+up\s+-d/.test(buildCase) && /compose_live\s+up\s+-d/.test(sh));
}

console.log('\n--- the push names the version tag of every service ---');
{
    const services = ['nginx', 'php', 'python', 'mariadb'];
    for (const s of services) {
        const re = new RegExp(`docker push "ghcr\\.io/\\$\\{IMAGE_OWNER\\}/astro-web-indexer-\\$\\{service\\}:\\$AWI_VERSION"`);
        check(`the ${s} image is pushed with the version tag`, re.test(pushCase));
    }
    check('the push does not touch compose', !/docker compose/.test(pushCase),
        'compose would merge the dev layer and tag the wrong image');
    check('the push refuses to go on without a credential',
        /ghcr_credential_present/.test(pushCase));
    check('  and the check comes before the first push',
        pushCase.indexOf('ghcr_credential_present') < pushCase.indexOf('docker push'),
        'the check must run first, not after the layers are prepared');
}

console.log('\n--- the credential check is honest ---');
{
    const fn = sh.slice(sh.indexOf('ghcr_credential_present()'), sh.indexOf('\n}\n', sh.indexOf('ghcr_credential_present()')));
    check('it looks at the docker config', /\.docker/.test(fn) && /config\.json/.test(fn));
    check('  and accepts a plain ghcr.io entry', /"ghcr\\?\.io"/.test(fn));
    check('  and leaves the push alone when a helper holds the token',
        /credsStore/.test(fn) && /credHelpers/.test(fn),
        'a helper is the only one that knows what it has');
}

console.log(bad === 0
    ? '\nOK: the build tags what the push names, and the push says what is missing'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);
