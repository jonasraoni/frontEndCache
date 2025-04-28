[![OJS compatibility](https://img.shields.io/badge/ojs-3.3-brightgreen)](https://github.com/pkp/ojs/tree/stable-3_3_0)
[![OMP compatibility](https://img.shields.io/badge/omp-3.3-brightgreen)](https://github.com/pkp/omp/tree/stable-3_3_0)
[![OPS compatibility](https://img.shields.io/badge/ops-3.3-brightgreen)](https://github.com/pkp/ops/tree/stable-3_3_0)
![GitHub release](https://img.shields.io/github/v/release/jonasraoni/frontEndCache?include_prereleases&label=latest%20release&filter=v1*)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/jonasraoni/frontEndCache)
![License type](https://img.shields.io/github/license/jonasraoni/frontEndCache)
![Number of downloads](https://img.shields.io/github/downloads/jonasraoni/frontEndCache/total)

# Front End Cache

## About

This is a site-wide plugin, which will generate gzipped cache for the whole installation, it also leverages the cache headers to decrease the server hammering.

If you need support for older OJS/OPS/OMP releases, see the [available branches](https://github.com/jonasraoni/frontEndCache/branches).

## Installation Instructions

We recommend installing this plugin using the Plugin Gallery within OJS/OPS/OMP. Log in with administrator privileges, navigate to `Settings` > `Website` > `Plugins`, and choose the Plugin Gallery. Find the `Front End Cache Plugin` there and install it.

> If for some reason, you need to install it manually:
> - Download the latest release (attention to the OJS/OPS/OMP version compatibility) or from GitHub (attention to grab the code from the right branch).
> - Create the folder `plugins/generic/frontEndCache` and place the plugin files in it.
> - Run the command `php lib/pkp/tools/installPluginVersion.php plugins/generic/frontEndCache/version.xml` at the main OJS/OPS/OMP folder, this will ensure the plugin is installed/upgraded properly.

After installing and enabling the plugin, access its settings to ensure everything fits your expectations, the plugin has some default values.

## Notes

- This is a site-wide plugin, which means its settings are shared across all the journals/presses/servers of the installation.

## License

This plugin is licensed under the GNU General Public License v3. See the file LICENSE for the complete terms of this license.

## System Requirements

- OJS/OMP/OPS 3.3.0-X.

## Contact/Support

If you have issues, please use the issue tracker (https://github.com/jonasraoni/frontEndCache/issues).
