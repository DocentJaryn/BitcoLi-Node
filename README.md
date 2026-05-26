# BitcoLi

A decentralized Lightning custodial node for small groups of people who trust each other.

![License: AGPLv3](https://img.shields.io/badge/licence-AGPLv3-blue)

🇨🇿 [Česky](README.cs.md)

---

## 🧠 The Idea

Current regulations (e.g. Travel Rule, MiCA, DORA, ...) make it practically impossible to run small Lightning services.

The result is:
- centralization
- greater risk when a single entity fails

BitcoLi goes the other way:

👉 many small nodes  
👉 multiple wallets in one app — if one is offline, send/receive through another  
👉 small balances ("beer money")  
👉 trust between people you actually know  

---

## ⚙️ How It Works

- each user/node has its own key (ed25519) derived from a seed
- the public key serves as an identifier
- the client derives keys separately for each node — so the client is identified by a different key on each node
- each node acts as an independent wallet with its own ledger
- only a client who has received an invitation (QR code / connection string) can use a node
- the client performs a handshake with the node, mutually verifying identity and exchanging keys (chacha20_poly1305) via x25519 for all further communication
- every operation is cryptographically signed:
  - the client signs payment requests
  - the node signs issued invoices and their status

👉 It is impossible to deny:
- "I didn't send that payment"
- "That invoice doesn't exist"

---

## 🚀 Installation

The client app is currently available as an open test release on Google Play:  
https://play.google.com/store/apps/details?id=com.bitcoli.client

The server plugin for Umbrel is available via the Community App Store:  
https://github.com/DocentJaryn/UmbrelStore

Installation automatically takes care of:
- LND integration
- Tor configuration
- backups

👉 No complex configuration required

---

## 👥 Groups and Trust Levels

Users are assigned trust levels, for example:

- low trust (e.g. repeatedly exceeds the allowed balance)
- newcomer
- acquaintance
- friend
- family

Each level defines:
- a maximum balance — exceeding it will block the user from issuing new invoices
- fees (planned; currently no fees)

---

## 🔄 Resilience

- transactions are automatically backed up in encrypted form (chacha20_poly1305, key derived from the seed separately for each backup) to selected other BitcoLi nodes
- **node failure**: after entering the seed and at least one address of a friendly BitcoLi node holding a backup, the last known transaction state is restored
- **backup loss**: the client can present signed transactions as proof — the node verifies its own signature and must include them in the history (planned); if it refuses, the client will see a warning that the node is cheating
- **seed loss**: obligations are resolved outside the system (real-world)

---

## 🌐 Networking

- communication runs primarily over Tor (.onion)
- Tor support in the client app is provided via the Orbot app; if Orbot is unavailable, the BitcoLi server's Tor proxy is used as a fallback
- no port forwarding required by default
- no public IP needed, though clearnet on port 27609 is also supported

---

## 🔐 Proof of Consequences

This system is not based on legal contracts or regulation — it is based on real relationships between people.

> "Cheat me? You'll face the consequences."  
> A node operator will never manage enough funds to make running away worthwhile — not at the cost of long-built relationships and a few Sats.

---

## 🍺 Philosophy

👉 small amounts  
👉 people you know  
👉 no bureaucracy  
👉 simple setup  
👉 undeniable balances  
👉 transaction signatures are the source of truth  

---

## 🤝 Contributing

Report bugs and suggestions via [GitHub Issues](../../issues). Pull requests are welcome.

This project takes a lot of time to maintain — you can support it by sending a Lightning donation to [donate@bitcoli.com](lightning:donate@bitcoli.com)

![Donate QR](https://bitcoli.com/lnd/qrdeposit/donate)

---

## ⚖️ License

This project is licensed under **AGPLv3**.

That means:
- you can use and modify it freely
- but if you run it as a service, you must publish your changes

---

## ⚠️ Disclaimer

This software is not a bank or a regulated service.

By using this software:
- you are entrusting funds to a specific person (the node operator)
- trust is based on the real world and personal relationships, not on laws or regulations

The author does not provide financial services, bears no responsibility for loss of funds, and provides software only.

👉 Never store more than you are willing to lose. Use at your own risk.
