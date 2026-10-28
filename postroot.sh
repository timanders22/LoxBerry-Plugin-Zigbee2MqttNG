#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall but *BEFORE* postupdate). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0  # Zero argument is shell command
PTEMPDIR=$1 # First argument is temp folder during install
PSHNAME=$2  # Second argument is Plugin-Name for scipts etc.
PDIR=$3     # Third argument is Plugin installation folder
PVERSION=$4 # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Base folder of LoxBerry

# 4.2.5 (Gruppe 38, V6): the LoxBerry home comes from /etc/environment; the
# installer passes it as fifth argument too (plugininstall.pl). Without both,
# and with a folder that is not the own one, nothing is done - a path built
# from an empty variable would be "/zigbee2mqttng" (chown -R as root).
LBHOMEDIR="${LBHOMEDIR:-$5}"
case "$PDIR" in zigbee2mqttng) ;; *) echo "<FAIL> unexpected plugin folder '$PDIR'"; exit 2;; esac
case "$LBHOMEDIR" in /?*) ;; *) echo "<FAIL> LoxBerry home folder unknown"; exit 2;; esac
LBPCGI="${LBPCGI:-$LBHOMEDIR/webfrontend/htmlauth/plugins}"
LBPHTML="${LBPHTML:-$LBHOMEDIR/webfrontend/html/plugins}"
LBPTEMPL="${LBPTEMPL:-$LBHOMEDIR/templates/plugins}"
LBPDATA="${LBPDATA:-$LBHOMEDIR/data/plugins}"
LBPLOG="${LBPLOG:-$LBHOMEDIR/log/plugins}"
LBPCONFIG="${LBPCONFIG:-$LBHOMEDIR/config/plugins}"
LBPSBIN="${LBPSBIN:-$LBHOMEDIR/sbin/plugins}"
LBPBIN="${LBPBIN:-$LBHOMEDIR/bin/plugins}"

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Loxberry Home is: $LBHOMEDIR"
echo "<INFO> Plugin installation folder is: $PDIR"

#source version file
. ${PTEMPPATH}/version.sh

# Zigbee2MqttNG installs into its own folder and runs its own service, so it never
# collides with the original Zigbee2Mqtt plugin (/opt/zigbee2mqtt, zigbee2mqtt.service)
# or with Zigbee2Lox, the former name of this plugin (/opt/zigbee2lox, zigbee2lox.service).
INSTALLDIR=/opt/zigbee2mqttng
SERVICE=zigbee2mqttng
# Folders (= service names) of the predecessor plugins whose network is taken
# over once on a fresh install. The first one found wins: Zigbee2Lox already
# took over the original, so it holds the newest data.
PREDECESSORS="zigbee2lox zigbee2mqtt"


BUILDDIR=$INSTALLDIR.new

ISUPGRADE=0
# 4.2.5 (Gruppe 38, V3): the copy back is checked. Until 4.2.4 "cp -f -r .../*"
# lost dot files and the modification times (state.json), and the backup in
# /tmp was removed even when the copy failed - with it the network key.
RUECKKOPIE_OK=1
SICHERUNG="/tmp/${PTEMPDIR}_upgrade"
startsperre_rueckkopie() {
    # the start block of update-config.php with the reason "rueckkopie": a
    # later "systemctl start" does not start either; only "Lift the start
    # block" on the Settings tab removes it, update-config.php never does
    SPERRE="$PCONFIG/startsperre"
    ALT=""
    [ -f "$SPERRE" ] && ALT=$(tr -d '\n' < "$SPERRE")
    case ",$ALT," in *,rueckkopie,*) return 0;; esac
    if [ -d "$PCONFIG" ] && (umask 077; printf '%s\n' "${ALT:+$ALT,}rueckkopie" > "$SPERRE"); then
        chown loxberry:loxberry "$SPERRE" 2>/dev/null
        chmod 600 "$SPERRE"
        echo "<INFO> Start block written: $SPERRE"
    else
        echo "<ERROR> The start block $SPERRE could not be written"
    fi
}
if [ -d "$SICHERUNG" ]; then
    echo "<INFO> Upgrade detected"
    ISUPGRADE=1

    # The service file of the new archive wins: it replaces the one in the
    # backup before the backup is copied back (only this one file)
    if [ -f "$LBHOMEDIR/config/plugins/$PDIR/zigbee2mqttng.service" ] && [ -d "$SICHERUNG/config/$PDIR" ]; then
        cp -f -- "$LBHOMEDIR/config/plugins/$PDIR/zigbee2mqttng.service" "$SICHERUNG/config/$PDIR/zigbee2mqttng.service" || RUECKKOPIE_OK=0
    fi

    echo "<INFO> Copy back existing config files"
    # the content with dot files, times and modes ("/." instead of "/*")
    if [ -d "$SICHERUNG/config/$PDIR" ]; then
        cp -a "$SICHERUNG/config/$PDIR/." "$PCONFIG/" || RUECKKOPIE_OK=0
    fi
    if [ -d "$SICHERUNG/data/$PDIR" ]; then
        cp -a "$SICHERUNG/data/$PDIR/." "$PDATA/" || RUECKKOPIE_OK=0
    fi
    # the files that hold the network, byte for byte (as preupgrade.sh)
    if [ "$RUECKKOPIE_OK" -eq 1 ]; then
        for f in configuration.yaml coordinator_backup.json database.db devices.yaml groups.yaml; do
            [ -f "$SICHERUNG/data/$PDIR/$f" ] || continue
            if ! cmp -s "$SICHERUNG/data/$PDIR/$f" "$PDATA/$f"; then
                echo "<ERROR> $f differs from its backup after copying back"
                RUECKKOPIE_OK=0
            fi
        done
    fi
    if [ "$RUECKKOPIE_OK" -eq 1 ]; then
        rm -f -r "$SICHERUNG"
        echo "<OK> Config and data copied back and checked"
    else
        echo "<ERROR> Copying back failed – the backup stays in $SICHERUNG; zigbee2mqtt is not started"
        startsperre_rueckkopie
    fi
fi

# ---------------------------------------------------------------------------
# Build zigbee2mqtt in $BUILDDIR. The running installation in $INSTALLDIR is
# only replaced once the build succeeded - a failed download or build keeps
# the previous zigbee2mqtt, so the Zigbee network keeps working.
# ---------------------------------------------------------------------------
BUILD_OK=0
BUILD_ERROR=""

build_zigbee2mqtt() {
    ARCH=$(uname -m)
    case $ARCH in
      x86_64)  NODE_ARCH="x64" ;;
      aarch64) NODE_ARCH="arm64" ;;
      armv7l)  NODE_ARCH="armv7l" ;;
      *)
        BUILD_ERROR="Unsupported architecture $ARCH - zigbee2mqtt needs x86_64, aarch64 or armv7l"
        return 1
        ;;
    esac

    rm -f -r "$BUILDDIR"
    echo "<INFO> Downloading zigbee2mqtt $ZIGBEE2MQTT_VERSION"
    git clone --quiet --branch "$ZIGBEE2MQTT_VERSION" --depth 1 https://github.com/Koenkk/zigbee2mqtt.git "$BUILDDIR" \
        || { BUILD_ERROR="Could not download zigbee2mqtt $ZIGBEE2MQTT_VERSION (internet connection?)"; return 1; }
    cd "$BUILDDIR" || { BUILD_ERROR="Could not enter $BUILDDIR"; return 1; }

    # NODE_VERSION is set in version.sh
    echo "<INFO> Downloading Node.js $NODE_VERSION ($NODE_ARCH)"
    NODE_TAR="node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz"
    wget -q "https://nodejs.org/dist/$NODE_VERSION/$NODE_TAR" \
        || { BUILD_ERROR="Could not download Node.js $NODE_VERSION"; return 1; }
    mkdir -p "$BUILDDIR/node"
    tar -xf "$NODE_TAR" --strip-components=1 -C "$BUILDDIR/node" \
        || { BUILD_ERROR="Could not unpack Node.js $NODE_VERSION"; return 1; }
    rm -f "$NODE_TAR"
    export PATH=$BUILDDIR/node/bin:$PATH

    echo "<INFO> Node.js $(node --version)"
    # 4.2.5: no "Update available" box of npm/pnpm in the install log - both read
    # update-notifier from npm_config_* (checked in pnpm 10.18.3: pnpm.cjs addEnv,
    # parseField, "config.updateNotifier !== false" before install/add)
    NPM_CONFIG_UPDATE_NOTIFIER=false npm install -g --silent "$(node -p "require('./package.json').packageManager")" \
        || { BUILD_ERROR="Could not install the package manager of zigbee2mqtt"; return 1; }
    echo "<INFO> Installing the dependencies of zigbee2mqtt (pnpm $(pnpm --version))"
    NPM_CONFIG_UPDATE_NOTIFIER=false pnpm i --frozen-lockfile || { BUILD_ERROR="pnpm install failed"; return 1; }

    echo "<INFO> Building zigbee2mqtt"
    pnpm run build || { BUILD_ERROR="Build of zigbee2mqtt failed"; return 1; }

    # The data folder becomes a link to the plugin data folder
    rm -f -r "$BUILDDIR/data"
    return 0
}

if build_zigbee2mqtt; then
    cd /
    echo "<INFO> Replacing the zigbee2mqtt installation"
    rm -f -r "$INSTALLDIR.old"
    if [ -e "$INSTALLDIR" ]; then
        mv "$INSTALLDIR" "$INSTALLDIR.old"
    fi
    mv "$BUILDDIR" "$INSTALLDIR"
    rm -f -r "$INSTALLDIR.old"
    BUILD_OK=1
    echo "<OK> zigbee2mqtt $ZIGBEE2MQTT_VERSION installed"
else
    cd /
    rm -f -r "$BUILDDIR"
    if [ -x "$INSTALLDIR/node/bin/node" ] && [ -f "$INSTALLDIR/index.js" ]; then
        echo "<ERROR> $BUILD_ERROR. The previous zigbee2mqtt installation is kept and started again. Please install the plugin again later."
    else
        echo "<FAIL> $BUILD_ERROR. zigbee2mqtt is not installed."
        exit 2
    fi
fi

chown -R loxberry:loxberry $INSTALLDIR

echo "<INFO> Linking log to log folder"
ln -s -f -n $PLOG $INSTALLDIR/log

echo "<INFO> Updating data folder"
ln -s -f -n $PDATA $INSTALLDIR/data

# Fresh installation next to (or instead of) a predecessor plugin: take over
# its network so no device has to be paired again.
MIGRATED=0
for ORIGDIR in $PREDECESSORS; do
    ORIGSERVICE=$ORIGDIR
    ORIGDATA=$LBHOMEDIR/data/plugins/$ORIGDIR
    ORIGCONFIG=$LBHOMEDIR/config/plugins/$ORIGDIR
    if [ "$ISUPGRADE" -ne "0" ] || [ "$MIGRATED" -ne "0" ] || [ "$PDIR" = "$ORIGDIR" ]; then
        continue
    fi
    if [ ! -f "$ORIGDATA/configuration.yaml" ] || [ -f "$PDATA/configuration.yaml" ]; then
        continue
    fi
    echo "<INFO> Plugin $ORIGDIR found - taking over its Zigbee network"
    if systemctl is-active --quiet $ORIGSERVICE; then
        echo "<INFO> Stopping service $ORIGSERVICE (only one service may use the Zigbee adapter)"
        systemctl stop $ORIGSERVICE
    fi
    if systemctl is-enabled --quiet $ORIGSERVICE 2>/dev/null; then
        systemctl disable $ORIGSERVICE
    fi
    cp -a "$ORIGDATA/." "$PDATA/"
    for f in mqtt.json service.json; do
        if [ -f "$ORIGCONFIG/$f" ]; then
            cp -f "$ORIGCONFIG/$f" "$PCONFIG/$f"
        fi
    done
    # The MQTT gateway reads the subscriptions of every installed plugin. The
    # predecessor would otherwise keep <topic>/# (original) or its device
    # list registered until it is uninstalled.
    for f in mqtt_subscriptions.cfg mqtt_conversions.cfg mqtt_resetaftersend.cfg; do
        if [ -f "$ORIGCONFIG/$f" ]; then
            : > "$ORIGCONFIG/$f"
        fi
    done
    # An update of the predecessor would enable and start its service again.
    # Zigbee2Lox even fetches its updates from this repository and would
    # install Zigbee2MqttNG again every night.
    perl -I"$LBHOMEDIR/libs/perllib" -e '
        use LoxBerry::System::PluginDB;
        foreach my $md5 (LoxBerry::System::PluginDB->search(folder => $ARGV[0])) {
            my $plugin = LoxBerry::System::PluginDB->plugin(md5 => $md5);
            next if (!$plugin);
            $plugin->{autoupdate} = "0";
            $plugin->save();
        }' "$ORIGDIR" && echo "<INFO> Automatic updates of $ORIGDIR disabled" \
        || echo "<WARNING> Could not disable the automatic updates of $ORIGDIR. Please uninstall $ORIGDIR."

    if [ "$ORIGDIR" = "zigbee2lox" ]; then
        # The copied bridge extension of Zigbee2Lox must not be loaded next to
        # ours, its control file is rewritten by update-config.php below.
        rm -f "$PDATA/external_extensions/zigbee2lox.mjs" "$PDATA/zigbee2lox.json"
        for f in devices groups info haus; do
            if [ -f "$PDATA/zigbee2lox_$f.json" ]; then
                mv -f "$PDATA/zigbee2lox_$f.json" "$PDATA/zigbee2mqttng_$f.json"
            fi
        done
        # The haus/tuer topics belong to this plugin now - the uninstall of
        # Zigbee2Lox must not delete them.
        rm -f "$ORIGDATA/zigbee2lox_haus.json"
    fi

    MIGRATED=1
    echo "<WARNING> The plugin $ORIGDIR is still installed. Please uninstall it - an update of it would start its service again and both would fight for the Zigbee adapter."
done

# 4.1.1 (B3, Bauart F): second copies left by an earlier installation are
# never taken over by a fresh one - they are set aside
if [ "$ISUPGRADE" -eq "0" ]; then
    php $PBIN/zweitschrift.php beiseite "$PCONFIG"
fi

echo "<INFO> Refresh config"
php $PBIN/update-config.php
RC_UPDATE=$?
if [ "$RC_UPDATE" -eq "3" ]; then
    echo "<ERROR> A configuration file of Zigbee2MqttNG cannot be read and has no readable second copy. zigbee2mqtt is not started, so the Zigbee network is not lost. The settings page of the plugin says what to do."
elif [ "$RC_UPDATE" -ne "0" ]; then
    echo "<WARNING> update-config.php ended with code $RC_UPDATE - see the log Service of the plugin"
fi

# 4.2.5 (V6): quoted, the two folders themselves, no glob
chown -R loxberry:loxberry "$PDATA" "$PCONFIG"

# if we have a new installation we setup the encryption
# https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt/issues/13
# A taken over network already has its key - generating a new one would cut off every device.
if [ "$ISUPGRADE" -eq "0" ] && [ "$MIGRATED" -eq "0" ]; then
    echo "<INFO> Fresh installation detected - Set encryption key"
    php $PBIN/setup-encryption.php
fi

# 4.1.1 (B4): the files with the broker password, the UI token and the
# network key are for loxberry only - also when they came from the archive
# (0644) or from a backup of 4.1.0
for f in "$PCONFIG/mqtt.json" "$PCONFIG/service.json" "$PCONFIG/meldungen.json" "$PCONFIG/aktionstoken" "$PCONFIG/startsperre" \
         "$PDATA/configuration.yaml" "$PDATA/coordinator_backup.json" \
         "$PCONFIG"/*.kaputt "$PDATA"/*.kaputt "$LBHOMEDIR/config/plugins/$PDIR".backup.* "$LBHOMEDIR/config/plugins/$PDIR".alt.*; do
    if [ -f "$f" ]; then
        chown loxberry:loxberry "$f"
        chmod 600 "$f"
    fi
done

echo "<INFO> Updating service config"
ln -f -s $PCONFIG/zigbee2mqttng.service /etc/systemd/system/$SERVICE.service

# Enable auto-start of the service
systemctl daemon-reload
systemctl enable $SERVICE

# 4.2.5 (Gruppe 38, V5, Regeln/06): started only on a fresh installation (also
# when a predecessor was taken over) or when it ran before the update - the
# marker of preupgrade.sh, at most 6 hours old (building zigbee2mqtt takes long
# on a Pi) - and only when the copy back succeeded. "enable" stays: a reboot of
# the LoxBerry starts the service, as before ("Stop" on the Settings tab does
# no "disable").
LIEF_VORHER="$LBPCONFIG/$PDIR.lief_vorher"
STARTEN=0
if [ "$RUECKKOPIE_OK" -ne 1 ]; then
    STARTEN=0
elif [ "$ISUPGRADE" -eq 0 ]; then
    STARTEN=1
elif [ -f "$LIEF_VORHER" ]; then
    T_MERKER=$(tr -cd '0-9' < "$LIEF_VORHER" 2>/dev/null)
    T_JETZT=$(date +%s)
    ALTER=$((T_JETZT - ${T_MERKER:-0}))
    if [ -n "$T_MERKER" ] && [ "$ALTER" -ge 0 ] && [ "$ALTER" -le 21600 ]; then
        STARTEN=1
    else
        echo "<INFO> The marker of the running service is older than 6 hours - it is not used"
    fi
fi
rm -f "$LIEF_VORHER"
if [ "$STARTEN" -eq 1 ]; then
    if systemctl start $SERVICE; then
        echo "<OK> zigbee2mqtt started"
    else
        echo "<WARNING> zigbee2mqtt did not start - see the Settings tab"
    fi
elif [ "$RUECKKOPIE_OK" -eq 1 ]; then
    echo "<INFO> zigbee2mqtt was stopped before the update and stays stopped – start it on the Settings tab"
fi

# 4.2.5 (Gruppe 38, V7): instead of REBOOT=1 Apache is reloaded - only when the
# php-yaml ini of Apache is younger than the start of the Apache main process,
# i.e. php-yaml came after Apache started. The ini in conf.d is a link made by
# phpenmod at install time; its own time is taken (stat without -L: dpkg keeps
# the build time on the file it points to). "reload" is graceful: running
# requests end normally.
apache_php_yaml() {
    INI=$(ls /etc/php/*/apache2/conf.d/*yaml*.ini 2>/dev/null | head -1)
    START=$(systemctl show -p ExecMainStartTimestamp --value apache2 2>/dev/null)
    T_INI=""
    T_START=""
    [ -n "$INI" ] && T_INI=$(stat -c %Y -- "$INI" 2>/dev/null)
    [ -n "$START" ] && T_START=$(date -d "$START" +%s 2>/dev/null)
    case "$T_INI" in ''|*[!0-9]*) T_INI="";; esac
    case "$T_START" in ''|*[!0-9]*) T_START="";; esac
    if [ -z "$T_INI" ] || [ -z "$T_START" ]; then
        echo "<WARNING> Apache: php-yaml ini or start time of Apache not readable - if the web interface reports php-yaml missing, restart the LoxBerry once"
        return 0
    fi
    if [ "$T_INI" -gt "$T_START" ]; then
        if systemctl reload apache2; then
            echo "<OK> Apache reloaded so the web interface knows php-yaml"
        else
            echo "<WARNING> Apache could not be reloaded - if the web interface reports php-yaml missing, restart the LoxBerry once"
        fi
    else
        echo "<INFO> Apache: no reload needed"
    fi
}
apache_php_yaml

if [ "$BUILD_OK" -ne "1" ]; then
    exit 1
fi
exit 0
