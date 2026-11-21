export function formatUsd(amount: string): string {
    const [whole, fraction = ''] = amount.split('.');

    return `$${whole}.${fraction.replace(/0+$/, '').padEnd(2, '0')}`;
}
