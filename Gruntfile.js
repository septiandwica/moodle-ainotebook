/* eslint no-undef: "error" */
/* eslint camelcase: 2 */
/* eslint-env node */

"use strict";

module.exports = function(grunt) {

    var path = require('path'),
        PWD = process.env.PWD || process.cwd();

    // The path to the purge_caches script inside the Moodle webserver container.
    var decachephp = "/var/www/html/admin/cli/purge_caches.php";

    grunt.initConfig({
        eslint: {
            amd: {src: ["amd/src/*.js"]}
        },
        terser: {
            amd: {
                files: [{
                    expand: true,
                    src: ["amd/src/*.js"],
                    rename: function(destPath, srcPath) {
                        var dest = srcPath.replace("src", "build");
                        dest = dest.replace(".js", ".min.js");
                        return dest;
                    }
                }],
                options: {
                    sourceMap: true
                }
            }
        },
        watch: {
            options: {
                nospawn: true,
                livereload: true
            },
            amd: {
                files: ["amd/src/**/*.js"],
                tasks: ["amd", "decache"]
            },
            css: {
                files: ["styles.css"],
                tasks: ["stylelint", "decache"]
            },
            php: {
                files: ["**/*.php", "!node_modules/**"],
                tasks: ["decache"]
            }
        },
        stylelint: {
            css: {
                src: ["styles.css"],
                options: {
                    configOverrides: {
                        rules: {
                            "at-rule-no-unknown": true,
                        }
                    }
                }
            }
        },
        exec: {
            decache: {
                // This command assumes php is available. 
                // If running via docker-compose, you might need to wrap this in 'docker exec'.
                cmd: "php " + decachephp,
                callback: function(error) {
                    if (!error) {
                        grunt.log.writeln("Moodle cache purged successfully.");
                    } else {
                        grunt.log.error("Could not purge cache. Ensure PHP is available in this environment.");
                    }
                }
            }
        }
    });

    // Load tasks.
    grunt.loadNpmTasks("grunt-contrib-watch");
    grunt.loadNpmTasks("grunt-exec");
    grunt.loadNpmTasks("grunt-terser");
    grunt.loadNpmTasks("grunt-eslint");
    grunt.loadNpmTasks("grunt-stylelint");

    // Register tasks.
    grunt.registerTask("amd", ["eslint:amd", "terser"]);
    grunt.registerTask("decache", ["exec:decache"]);
    grunt.registerTask("default", ["watch"]);
};
