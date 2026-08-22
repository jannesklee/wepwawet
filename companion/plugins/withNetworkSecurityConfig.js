const { withAndroidManifest, withDangerousMod } = require('@expo/config-plugins');
const fs = require('fs');
const path = require('path');

// Loopback/emulator-only addresses used by the local dev Nextcloud instance
// (docker-compose.dev.yml). Real Nextcloud servers are always https://, so
// permitting cleartext HTTP to just these three hosts is safe even if it
// ends up in a release build - none of them resolve to anything outside a
// developer's own machine or emulator.
const DEV_HOSTS = ['10.0.2.2', 'localhost', '127.0.0.1'];

const CONFIG_XML = `<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
  <base-config cleartextTrafficPermitted="false" />
  <domain-config cleartextTrafficPermitted="true">
${DEV_HOSTS.map((h) => `    <domain includeSubdomains="false">${h}</domain>`).join('\n')}
  </domain-config>
</network-security-config>
`;

function withNetworkSecurityConfigXml(config) {
  return withDangerousMod(config, [
    'android',
    async (config) => {
      const xmlDir = path.join(
        config.modRequest.platformProjectRoot,
        'app/src/main/res/xml',
      );
      fs.mkdirSync(xmlDir, { recursive: true });
      fs.writeFileSync(
        path.join(xmlDir, 'network_security_config.xml'),
        CONFIG_XML,
      );
      return config;
    },
  ]);
}

function withNetworkSecurityConfigManifest(config) {
  return withAndroidManifest(config, (config) => {
    const mainApplication =
      config.modResults.manifest.application[0];
    mainApplication.$['android:networkSecurityConfig'] =
      '@xml/network_security_config';
    return config;
  });
}

module.exports = function withNetworkSecurityConfig(config) {
  config = withNetworkSecurityConfigXml(config);
  config = withNetworkSecurityConfigManifest(config);
  return config;
};
