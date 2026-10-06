{% if not helpers.empty('OPNsense.AppId.general.enabled') %}
appidd_enable="YES"
appidd_setup="/usr/local/opnsense/scripts/appid/setup.sh"
appidd_config="/usr/local/etc/appidd/appidd.conf"
appidd_pidfile="/var/run/appidd.pid"
{% else %}
appidd_enable="NO"
{% endif %}
