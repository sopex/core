#!/usr/local/bin/python3

"""
    Copyright (c) 2026 Konstantinos Spartalis <cspartalis@potatonetworks.com>
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
     this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in the
     documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

"""
import json

def get_blocklists():
    return {
        "     General Blocklists": {
            "atf": "Abuse.ch - ThreatFox IOC database",
            "ag": "AdGuard List",
            "el": "EasyList",
            "ep": "EasyPrivacy"
        },
        "    Hagezi Multi - Clean the Internet": {
            "hgz001": "LIGHT - Basic protection",
            "hgz002": "NORMAL - All-round protection",
            "hgz003": "PRO - Extended protection",
            "hgz005": "PRO++ - Maximum protection",
            "hgz007": "ULTIMATE - Aggressive protection",
        },
        "   Hagezi Targeted Lists": {
            "hgz009": "Fake - scams / fakes",
            "hgz010": "Pop-Up Ads",
            "hgz011": "Threat Intelligence Feeds",
            "hgz014": "DoH/VPN/TOR/Proxy Bypass",
            "hgz015": "Safesearch not supported",
            "hgz016": "Dynamic DNS blocking",
            "hgz017": "Badware Hoster blocking",
            "hgz018": "Anti Piracy",
            "hgz019": "Gambling",
            "hgz023": "Social Networks"
        },
        "  OISD Blocklists": {
            "oisd0": "Ads Blocklist",
            "oisd1": "Big Blocklist (incl. Ads)",
            "oisd2": "NSFW Blocklist"
        },
        " Misc Blocklists": {
            "sb": "Steven Black List",
            "yy": "YoYo List"
        },
        "Hagezi smaller verions of lists": {
            "hgz004": "Multi PRO mini ",
            "hgz006": "Multi PRO++ mini",
            "hgz008": "Multi ULTIMATE mini",
            "hgz012": "Threat Intelligence Feeds - Medium",
            "hgz013": "Threat Intelligence Feeds - Mini",
            "hgz020": "Gambling - Medium",
            "hgz021": "Gambling - Mini"
        }
    }

if __name__ == '__main__':
    print(json.dumps(get_blocklists()))
