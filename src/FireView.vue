<template>
    <k-inside>
        <k-header data-has-buttons="true">
            <k-header-title>Fire up your cache!</k-header-title>
            <k-header-buttons slot="buttons">
                <k-button-group>
                    <k-button v-if="!isHeatingUp && index === 0" variant="filled" icon="fire" theme="positive" @click="start()">Fire up</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="fire" theme="positive" @click="start()">Continue</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="cancel" theme="negative" @click="reset()">Extinguish</k-button>
                    <k-button v-if="isHeatingUp" variant="filled" icon="cancel" theme="negative" @click="pause()">Stop</k-button>
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
                    <tr v-for="(item, i) in items" :key="i" :ref="'row' + i">
                        <td class="k-table-index-column">
                            <span class="k-table-index">{{ i + 1 }}</span>
                        </td>
                        <td data-mobile="true">
                            <div class="truncate">{{ item.url }}</div>
                        </td>
                        <td class="k-state-column" data-mobile="true">
                            <div class="badge" :class="item.state">
                                <k-icon v-if="item.state === 'no-fire'" type="blaze"/>
                                <k-icon v-if="item.state === 'fire-up'" type="fire"/>
                                <k-icon v-if="item.state === 'fire-on'" type="fireFilled"/>
                                <span>{{ stateText(item.state) }}</span>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </k-grid>

    </k-inside>
</template>

<script>
export default {
    name: "fireView",
    data() {
        return {
            isHeatingUp: false,
            index: 0,
            items: [],
        }
    },
    created() {
        this.$api.get("fire/pages").then((data) => {
            this.items = data;
        });
    },
    methods: {
        stateText(state) {
            return state.replace(/-/g, " ");
        },
        heatUp(index) {
            this.index = index;
            if (index < this.items.length && this.isHeatingUp === true) {
                this.items[index].state = "fire-up";
                this.$api.post("fire/up", this.items[index]).then((data) => {
                    this.$set(this.items, index, data);
                    setTimeout(() => {
                        this.heatUp(index + 1);
                    }, 500);
                });
            }
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
            this.$api.get("fire/pages").then((data) => {
                this.items = data;
            });
        },
    },
}
</script>

<style lang="scss" scoped>

.k-table-index-column {
    width: 3rem;
}

.k-bar {
    padding-bottom: var(--spacing-4);
}

.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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

.k-language-column {
    width: 6rem;
}

.k-state-column {
    text-align: right;
    width: 9rem;
}
</style>
