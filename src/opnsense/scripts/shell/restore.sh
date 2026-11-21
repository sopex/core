#!/bin/sh

# Copyright (c) 2017-2026 Franco Fichtner <franco@opnsense.org>
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions
# are met:
#
# 1. Redistributions of source code must retain the above copyright
#    notice, this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#    notice, this list of conditions and the following disclaimer in the
#    documentation and/or other materials provided with the distribution.
#
# THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND
# ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
# IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
# ARE DISCLAIMED.  IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE
# FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
# DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
# OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
# HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
# OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
# SUCH DAMAGE.

set -e

XMLLINT="$(which xmllint 2> /dev/null || echo true)"
DIFF="$(which colordiff 2> /dev/null || echo cat)"

if [ ! -d /conf/backup ]; then
	echo "No backups available."
	exit 0
fi

BACKUPS="$(cd /conf/backup; find -s . -name "config-[0-9]*.xml" | tail -n 24)"

if [ -z "${BACKUPS}" ]; then
	echo "No backups available."
	exit 0
fi

DATED=

for BACKUP in ${BACKUPS}; do
	DATETIME=${BACKUP#*config-}
	DATETIME=${DATETIME%.xml}
	SUBSEC=${DATETIME#*.}
	DATETIME=${DATETIME%.*}
	STAMP=$(date -r "${DATETIME}" '+%Y-%m-%dT%H:%M:%S' 2> /dev/null) || continue
	${XMLLINT} --noout /conf/backup/${BACKUP} 2> /dev/null || continue
	USER=$(${XMLLINT} /conf/backup/${BACKUP} --xpath 'string(/opnsense/revision/username)')
	NOTES=$(${XMLLINT} /conf/backup/${BACKUP} --xpath 'string(/opnsense/revision/description)')
	if [ -n "${USER}" ]; then
		NOTES="${USER}: ${NOTES}"
	fi
	LEN=$(echo "${NOTES}" | awk '{ print length }')
	NOTES=$(echo "${NOTES}" | cut -c1-44)
	if [ "${LEN}" -gt 44 ]; then
		NOTES="${NOTES}..."
	fi

	# write a line with all required info that is prefixed
	# with a sortable time stamp for our next step...
	DATED="${DATED}${STAMP}.${SUBSEC} ${DATETIME} ${BACKUP} ${NOTES}
"
done

SORTED="$(echo -n "${DATED}" | sort -r)"
INDEX=0
RESTORE=
REMAPPED_TMP=

cleanup_remapped_tmp()
{
	if [ -n "${REMAPPED_TMP}" ]; then
		rm -f "${REMAPPED_TMP}"
		REMAPPED_TMP=
	fi
}

trap cleanup_remapped_tmp EXIT INT TERM HUP

while [ -z "${RESTORE}" ]; do
	clear

	echo "${SORTED}" | while read SORT DATETIME BACKUP NOTES; do
		if [ ${INDEX} -ne 0 ]; then
			# carefully crafted whitespace pattern with
			# embedded alignment tab, edit carefully
			printf "%2d. $(date -r ${DATETIME}) ${NOTES}\n" ${INDEX}
		fi
		INDEX=$((INDEX+1))
	done

	echo
	read -p "Select backup to review or leave blank to exit: " SELECT

	if [ -z "${SELECT}" ]; then
		exit 0
	fi

	RESTORE="$(echo "${SORTED}" | while read SORT DATETIME BACKUP NOTES; do
		if [ ${INDEX} -ne 0 -a ${INDEX} = "${SELECT}" ]; then
			echo "${BACKUP}"
			break
		fi
		INDEX=$((INDEX+1))
	done)"

	echo

	if [ -n "${RESTORE}" ]; then
		if cmp -s /conf/config.xml /conf/backup/${RESTORE}; then
			echo "This configuration backup has no changes."
			RESTORE=
		fi
	fi

	if [ -n "${RESTORE}" ]; then
		diff -u /conf/config.xml /conf/backup/${RESTORE} | \
		    ${DIFF} | less -R -P "Viewing resulting diff - press UP/DOWN to review, 'q' to continue"

		clear
		PREFLIGHT="/usr/local/opnsense/scripts/firmware/preflight.py"
		RESTORE_SRC="/conf/backup/${RESTORE}"
		REMAPPED_TMP=
		PF_RET=0
		if [ -x "${PREFLIGHT}" ]; then
			set +e
			${PREFLIGHT} --mode restore --config "${RESTORE_SRC}" --text --exit-on-blocker
			PF_RET=${?}
			set -e
			echo
		fi
		if [ ${PF_RET} -ne 0 ]; then
			echo "Pre-flight validation reported BLOCKER issue(s) for this backup."
			echo "You may enter interface remapping pairs (e.g. igb0=ix0,igb1=ix1), type OVERRIDE, or press Enter to cancel."
			read -p "Action [old=new,... | OVERRIDE | Enter to cancel]: " YN
			if [ "${YN}" = "OVERRIDE" ]; then
				:
			elif [ -n "${YN}" -a "${YN#*=}" != "${YN}" ]; then
				REMAPPED_TMP=$(mktemp /tmp/restore_remapped.XXXXXX)
				set +e
				${PREFLIGHT} --mode restore --config "${RESTORE_SRC}" --remap "${YN}" --write-remapped "${REMAPPED_TMP}" --text --exit-on-blocker
				PF_REMAP_RET=${?}
				set -e
				echo
				if [ ${PF_REMAP_RET} -eq 0 -a -s "${REMAPPED_TMP}" ]; then
					read -p "Remapping cleared blockers. Proceed restoring remapped backup? [y/N] " CONFIRM
					case ${CONFIRM} in
					[yY])
						RESTORE_SRC="${REMAPPED_TMP}"
						;;
					*)
						rm -f "${REMAPPED_TMP}"
						REMAPPED_TMP=
						RESTORE=
						;;
					esac
				else
					echo "Remapping did not resolve all blockers."
					read -p "Type OVERRIDE to restore remapped backup anyway, or press Enter to cancel: " CONFIRM
					if [ "${CONFIRM}" = "OVERRIDE" -a -s "${REMAPPED_TMP}" ]; then
						RESTORE_SRC="${REMAPPED_TMP}"
					else
						rm -f "${REMAPPED_TMP}"
						REMAPPED_TMP=
						RESTORE=
					fi
				fi
			else
				RESTORE=
			fi
		else
			read -p "Do you want to proceed restoring this backup? [y/N] " YN

			case ${YN} in
			[yY])
				;;
			*)
				RESTORE=
				;;
			esac
		fi
	fi

	echo
done

cp "${RESTORE_SRC}" /conf/config.xml
cleanup_remapped_tmp

read -p "Do you want to reboot to apply the backup cleanly? [y/N]: " YN

case ${YN} in
[yY])
	/usr/local/etc/rc.reboot
	;;
*)
	/usr/local/etc/rc.reload_all
	;;
esac
