<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    commands: Record<string, Record<string, any>>;
    artisanOutput?: string;
}>();

const selectedCommand = ref<string>(Object.keys(props.commands)[0]);

interface Arguments {
    type: string;
    multiple?: boolean;
    options?: Record<string, any> | Array<any>;
}

const selectedCommandParameters = computed<Record<string, Arguments>>(
    () => props.commands[selectedCommand.value],
);

const form = useForm<{
    args: Record<string, any>;
    selectedCommand: string;
}>({
    args: {},
    selectedCommand: selectedCommand.value,
});

const submit = () => form.get(route('run'));
</script>

<template>
    <select v-model="selectedCommand">
        <option
            v-for="item in Object.keys(commands)"
            :key="item"
            v-text="item"
            :value="item"
        />
    </select>
    <div v-for="(value, param) in selectedCommandParameters" :key="param">
        <select
            v-if="value.type === 'select'"
            :multiple="value.multiple"
            v-model="form.args[param]"
        >
            <option
                v-for="(optionVal, optionText) in value.options"
                :key="optionText"
                v-text="value.options instanceof Array ? optionVal : optionText"
                :value="optionVal"
            />
        </select>
    </div>

    <button v-if="!form.processing" @click="submit">Run</button>
    <div v-else>Running command...</div>

    <pre v-text="artisanOutput" />
</template>

<style scoped></style>
