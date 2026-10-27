<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Password from 'primevue/password';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Вход" />
    <div class="login-glow flex min-h-screen items-center justify-center bg-page px-4">
        <form
            class="flex w-full max-w-[400px] flex-col gap-4 rounded-[24px] border border-line bg-capsule p-8 shadow-raised"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1">
                <Link href="/" class="flex items-center gap-2 text-[25px] font-bold">
                    <span class="size-6 rounded-[6px] border-[3px] border-lavender" aria-hidden="true" />
                    Homefinder
                </Link>
                <p class="text-[14px] text-muted">Кабинет отдела продаж</p>
            </div>

            <Message v-if="form.errors.email" severity="error" size="small" variant="simple">
                {{ form.errors.email }}
            </Message>

            <label class="flex flex-col gap-1">
                <span class="text-[12px] text-muted">Email</span>
                <InputText v-model="form.email" type="email" autocomplete="username" required fluid />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[12px] text-muted">Пароль</span>
                <Password
                    v-model="form.password"
                    :feedback="false"
                    toggle-mask
                    autocomplete="current-password"
                    required
                    fluid
                />
            </label>

            <label class="flex items-center gap-2 text-[14px]">
                <Checkbox v-model="form.remember" binary />
                Запомнить меня
            </label>

            <Button type="submit" label="Войти" :loading="form.processing" fluid />

            <p class="text-[12px] text-muted">Демо-доступ: test@example.com / password</p>
        </form>
    </div>
</template>

<style scoped>
/* The glow of the product page behind the form. */
.login-glow {
    background-image: radial-gradient(50% 40% at 50% 40%, color-mix(in srgb, var(--color-accent) 18%, transparent), transparent);
}
</style>
