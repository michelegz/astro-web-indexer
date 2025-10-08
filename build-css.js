const { exec } = require('child_process');
const path = require('path');

const tailwindcss = path.join('node_modules', '.bin', 'tailwindcss');

const command = `${tailwindcss} -i ./src/assets/css/input.css -o ./src/assets/css/output.css --minify`;

const child = exec(command, (error, stdout, stderr) => {
  if (error) {
    console.error(`Error executing Tailwind CSS: ${error}`);
    return;
  }
  if (stderr) {
    console.error(`Tailwind CSS stderr: ${stderr}`);
  }
  console.log(`Tailwind CSS stdout: ${stdout}`);
});

child.on('exit', (code) => {
  console.log(`Tailwind CSS build process finished with code ${code}.`);
  process.exit(code);
});
