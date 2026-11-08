# Module directory map

```text
modules/
├── messages/
│   ├── Conversations/
│   ├── MessageRecords/
│   ├── Delivery/
│   ├── Attachments/
│   ├── Composition/
│   └── Policies/
├── calls/
│   ├── Rooms/
│   ├── Participants/
│   ├── Signaling/
│   ├── Providers/
│   ├── Recording/
│   └── Quality/
├── feed/
│   ├── Timeline/
│   ├── Ranking/
│   ├── Subscriptions/
│   └── Recommendations/
├── channels/
│   ├── Publishing/
│   ├── Discovery/
│   └── Subscribers/
├── users/
│   ├── Profiles/
│   └── Devices/
├── media/
│   ├── Storage/
│   └── Processing/
├── notifications/
│   ├── Delivery/
│   └── Preferences/
├── search/
│   ├── Indexing/
│   └── Providers/
├── shorts/
├── videos/
├── communities/
├── security/
├── moderation/
├── reactions/
├── analytics/
├── settings/
├── files/
└── shared/
    └── Support/
```

Each nested directory has its own responsibility note. Add production classes when a concrete use case is extracted; do not fill the tree with empty classes. The first working vertical slice is `channels` plus `feed`, and the channel detail page is in `public/channel/`.
