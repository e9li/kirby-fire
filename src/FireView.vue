<template>
    <k-panel-inside>
        <k-header data-has-buttons="true">
            <k-header-title>Fire up your cache!</k-header-title>
            <k-header-buttons slot="buttons">
                <k-button-group>
                    <k-button v-if="!isHeatingUp && index === 0" variant="filled" icon="fire" theme="positive" @click="start()">Fire up</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="fire" theme="positive" @click="start()">Continue</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="cancel" theme="negative" @click="reset()">Extinguish</k-button>
                    <k-button v-if="isHeatingUp" variant="filled" icon="cancel" theme="negative" @click="pause()">Stop</k-button>
                    <k-button v-if="!isHeatingUp" variant="filled" icon="trash" @click="clearCache()">Clear cache</k-button>
                </k-button-group>
            </k-header-buttons>
        </k-header>
        <k-grid style="--columns: 1; gap: var(--spacing-8)">
            <k-empty v-if="items.length === 0" icon="boiler" text="No pages on fire"/>
            <div v-if="items.length > 0" class="k-table">
                <table>
                    <thead>
                    <tr>
                        <th class="k-table-index-column">#</th>
                        <th class="k-boiler-url" data-mobile="true">URL</th>
                        <th class="k-state-column" data-mobile="true">State</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="(item, i) in items" :key="item.url">
                        <td class="k-table-index-column">
                            <span class="k-table-index">{{ i + 1 }}</span>
                        </td>
                        <td data-mobile="true">
                            <div class="truncate">{{ item.url }}</div>
                            <div v-if="item.error" class="error">{{ item.error }}</div>
                        </td>
                        <td class="k-state-column" data-mobile="true">
                            <div class="badge" :class="item.state">
                                <k-icon v-if="item.state === 'no-fire'" type="blaze"/>
                                <k-icon v-if="item.state === 'fire-up'" type="fire"/>
                                <k-icon v-if="item.state === 'fire-on'" type="fireFilled"/>
                                <k-icon v-if="item.state === 'extinguished'" type="cancel"/>
                                <span>{{ stateText(item.state) }}</span>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </k-grid>

    </k-panel-inside>
</template>

<script>
// pause between requests, keeps the server from being hammered
const PACE_MS = 150;

export default {
    name: "fireView",
    data() {
        return {
            isHeatingUp: false,
            index: 0,
            items: [],
            known: new Set(),
        }
    },
    created() {
        this.load();
    },
    methods: {
        stateText(state) {
            return state.replace(/-/g, " ");
        },
        load() {
            this.$api.get("fire/pages").then((data) => {
                this.items = data;
                this.known = new Set(data.map((item) => item.url));
            });
        },
        queueMedia(index, media) {
            // thumbs of a warmed page are queued right behind it, so Kirby's
            // media route generates them as part of the same crawl
            const fresh = (media || []).filter((url) => !this.known.has(url));

            fresh.forEach((url) => this.known.add(url));

            if (fresh.length > 0) {
                const additions = fresh.map((url) => ({
                    url,
                    language: this.items[index].language,
                    state: "no-fire",
                }));
                this.items.splice(index + 1, 0, ...additions);
            }
        },
        heatUp(index) {
            this.index = index;

            if (index >= this.items.length || this.isHeatingUp !== true) {
                return;
            }

            this.items[index].state = "fire-up";

            this.$api.post("fire/up", this.items[index])
                .then((data) => {
                    this.items[index] = data;
                    this.queueMedia(index, data.media);
                })
                .catch(() => {
                    // a failed request must not stall the crawl
                    this.items[index] = {
                        ...this.items[index],
                        state: "extinguished",
                        error: "Request failed",
                    };
                })
                .finally(() => {
                    setTimeout(() => {
                        this.heatUp(index + 1);
                    }, PACE_MS);
                });
        },
        start() {
            this.isHeatingUp = true;
            this.heatUp(this.index);
        },
        pause() {
            this.isHeatingUp = false;
        },
        reset() {
            this.isHeatingUp = false;
            this.index = 0;
            this.load();
        },
        clearCache() {
            this.$api.post("fire/clear").then(() => {
                this.reset();
            });
        },
    },
}
</script>

<style lang="scss" scoped>

.k-table-index-column {
    width: 4.5rem;
}

.k-bar {
    padding-bottom: var(--spacing-4);
}

.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.error {
    color: var(--color-red-600);
    font-size: var(--text-xs);
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-1);
    border-radius: var(--rounded-sm);
    padding: var(--spacing-1) var(--spacing-2);
    background: var(--color-gray-200);
    color: var(--color-gray-800);
}

.fire-up {
    padding-left: var(--spacing-1);
    background: var(--color-yellow-300);
    color: var(--color-yellow-800);
}

.fire-on {
    padding-left: var(--spacing-1);
    background: var(--color-green-300);
    color: var(--color-green-800);
}

.extinguished {
    padding-left: var(--spacing-1);
    background: var(--color-red-300);
    color: var(--color-red-800);
}

.k-language-column {
    width: 6rem;
}

.k-state-column {
    text-align: right;
    width: 9rem;
}
</style>
